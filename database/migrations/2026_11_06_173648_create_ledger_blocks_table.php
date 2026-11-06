<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The sealed ledger: a chain of blocks linked by hash. Each block stores the hash of the
     * previous one, so altering an old block invalidates EVERY later block.
     *
     * The application computes the canonical payload hash and the Merkle root (that needs the
     * canonical serialization); PostgreSQL enforces everything that can be checked without it,
     * for every role, in a BEFORE INSERT trigger under an advisory lock:
     *
     * - the first block is the genesis, at height 0, pointing to the zero hash;
     * - every other block is the next height and points to the hash of the current head;
     * - `hash` is SHA-256 of height, prev_hash, merkle_root and payload_hash joined by a NUL
     *   byte (the formula of the old system), recomputed here: a block cannot be inserted with
     *   a hash of its own choosing;
     * - sealed_at is the real clock at the insert.
     *
     * Append-only: UPDATE, DELETE and TRUNCATE are rejected.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE ledger_blocks (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                height integer NOT NULL,
                kind varchar(20) NOT NULL,
                period_key varchar(20) NULL,
                prev_hash char(64) NOT NULL,
                merkle_root char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                hash char(64) NOT NULL,
                payload jsonb NOT NULL,
                leaves_count integer NOT NULL DEFAULT 0,
                sealed_at timestamptz NOT NULL DEFAULT now(),
                sealed_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT ledger_blocks_height_unique UNIQUE (height),
                CONSTRAINT ledger_blocks_hash_unique UNIQUE (hash),
                CONSTRAINT ledger_blocks_period_unique UNIQUE (kind, period_key),
                CONSTRAINT ledger_blocks_kind_check CHECK (kind IN ('genesis', 'weekly', 'monthly')),
                CONSTRAINT ledger_blocks_genesis_height CHECK ((kind = 'genesis') = (height = 0)),
                CONSTRAINT ledger_blocks_genesis_period CHECK ((kind = 'genesis') = (period_key IS NULL)),
                CONSTRAINT ledger_blocks_weekly_period CHECK (kind <> 'weekly' OR period_key ~ '^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$'),
                CONSTRAINT ledger_blocks_monthly_period CHECK (kind <> 'monthly' OR period_key ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT ledger_blocks_hex_hashes CHECK (
                    prev_hash ~ '^[0-9a-f]{64}$' AND merkle_root ~ '^[0-9a-f]{64}$'
                    AND payload_hash ~ '^[0-9a-f]{64}$' AND hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT ledger_blocks_leaves_count CHECK (leaves_count >= 0),
                CONSTRAINT ledger_blocks_payload_object CHECK (jsonb_typeof(payload) = 'object')
            );

            CREATE INDEX ledger_blocks_sealed_by_index ON ledger_blocks (sealed_by);

            CREATE OR REPLACE FUNCTION ledger_block_hash(height integer, prev_hash text, merkle_root text, payload_hash text)
            RETURNS char(64) LANGUAGE sql IMMUTABLE AS $$
                SELECT encode(sha256(
                    convert_to(height::text, 'UTF8') || '\x00'::bytea
                    || convert_to(prev_hash, 'UTF8') || '\x00'::bytea
                    || convert_to(merkle_root, 'UTF8') || '\x00'::bytea
                    || convert_to(payload_hash, 'UTF8')
                ), 'hex')
            $$;

            CREATE OR REPLACE FUNCTION ledger_blocks_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                head ledger_blocks%ROWTYPE;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('ledger_blocks_chain'));
                SELECT * INTO head FROM ledger_blocks ORDER BY height DESC LIMIT 1;

                IF NOT FOUND THEN
                    IF NEW.height <> 0 OR NEW.kind <> 'genesis' OR NEW.prev_hash <> repeat('0', 64) THEN
                        RAISE EXCEPTION 'The first block of the sealed ledger must be the genesis at height 0 pointing to the zero hash'
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'ledger_blocks_chain_link';
                    END IF;
                ELSIF NEW.height <> head.height + 1 OR NEW.prev_hash <> head.hash THEN
                    RAISE EXCEPTION 'A new block must be height % and point to the current head', head.height + 1
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'ledger_blocks_chain_link';
                END IF;

                IF NEW.hash <> ledger_block_hash(NEW.height, NEW.prev_hash, NEW.merkle_root, NEW.payload_hash) THEN
                    RAISE EXCEPTION 'The block hash does not match its height, link, Merkle root and payload hash'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'ledger_blocks_hash_formula';
                END IF;

                NEW.sealed_at := clock_timestamp();
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER ledger_blocks_chain BEFORE INSERT ON ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION ledger_blocks_chain();

            CREATE TRIGGER ledger_blocks_no_update BEFORE UPDATE ON ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER ledger_blocks_no_delete BEFORE DELETE ON ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER ledger_blocks_no_truncate BEFORE TRUNCATE ON ledger_blocks
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS ledger_blocks;
            DROP FUNCTION IF EXISTS ledger_blocks_chain();
            DROP FUNCTION IF EXISTS ledger_block_hash(integer, text, text, text);
        SQL);
    }
};
