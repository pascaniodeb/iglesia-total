<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archive of the old system's sealed ledger: its blocks copied verbatim (hashes, payload,
     * the date it was sealed), so the custody of the books before the cut-over stays verifiable
     * with the same code that verifies the new chain.
     *
     * It is a second chain, never mixed with `ledger_blocks`: the new chain's genesis cites the
     * head of this one. PostgreSQL checks, as for the new chain, that the heights are contiguous
     * from the genesis, that every block points to the hash of the previous one and that its hash
     * is the formula of the old system. Unlike the new chain, `sealed_at` is the original date
     * (the clock of the import goes in `archived_at`), and the user who sealed is the old
     * system's integer id.
     *
     * Append-only: UPDATE, DELETE and TRUNCATE are rejected.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE legacy_ledger_blocks (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NOT NULL,
                height integer NOT NULL,
                kind varchar(20) NOT NULL,
                period_key varchar(20) NULL,
                prev_hash char(64) NOT NULL,
                merkle_root char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                hash char(64) NOT NULL,
                payload jsonb NOT NULL,
                sealed_at timestamptz NOT NULL,
                legacy_sealed_by integer NULL,
                anchor_key text NULL,
                archived_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT legacy_ledger_blocks_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT legacy_ledger_blocks_height_unique UNIQUE (height),
                CONSTRAINT legacy_ledger_blocks_hash_unique UNIQUE (hash),
                CONSTRAINT legacy_ledger_blocks_period_unique UNIQUE (kind, period_key),
                CONSTRAINT legacy_ledger_blocks_kind_check CHECK (kind IN ('genesis', 'weekly', 'monthly')),
                CONSTRAINT legacy_ledger_blocks_genesis_height CHECK ((kind = 'genesis') = (height = 0)),
                CONSTRAINT legacy_ledger_blocks_genesis_period CHECK ((kind = 'genesis') = (period_key IS NULL)),
                CONSTRAINT legacy_ledger_blocks_weekly_period CHECK (kind <> 'weekly' OR period_key ~ '^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$'),
                CONSTRAINT legacy_ledger_blocks_monthly_period CHECK (kind <> 'monthly' OR period_key ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT legacy_ledger_blocks_hex_hashes CHECK (
                    prev_hash ~ '^[0-9a-f]{64}$' AND merkle_root ~ '^[0-9a-f]{64}$'
                    AND payload_hash ~ '^[0-9a-f]{64}$' AND hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT legacy_ledger_blocks_payload_object CHECK (jsonb_typeof(payload) = 'object')
            );

            CREATE OR REPLACE FUNCTION legacy_ledger_blocks_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                head legacy_ledger_blocks%ROWTYPE;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('legacy_ledger_blocks_chain'));
                SELECT * INTO head FROM legacy_ledger_blocks ORDER BY height DESC LIMIT 1;

                IF NOT FOUND THEN
                    IF NEW.height <> 0 OR NEW.kind <> 'genesis' OR NEW.prev_hash <> repeat('0', 64) THEN
                        RAISE EXCEPTION 'The first archived block must be the genesis at height 0 pointing to the zero hash'
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'legacy_ledger_blocks_chain_link';
                    END IF;
                ELSIF NEW.height <> head.height + 1 OR NEW.prev_hash <> head.hash THEN
                    RAISE EXCEPTION 'A new archived block must be height % and point to the current head', head.height + 1
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'legacy_ledger_blocks_chain_link';
                END IF;

                IF NEW.hash <> ledger_block_hash(NEW.height, NEW.prev_hash, NEW.merkle_root, NEW.payload_hash) THEN
                    RAISE EXCEPTION 'The archived block hash does not match its height, link, Merkle root and payload hash'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'legacy_ledger_blocks_hash_formula';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER legacy_ledger_blocks_chain BEFORE INSERT ON legacy_ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION legacy_ledger_blocks_chain();

            CREATE TRIGGER legacy_ledger_blocks_no_update BEFORE UPDATE ON legacy_ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER legacy_ledger_blocks_no_delete BEFORE DELETE ON legacy_ledger_blocks
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER legacy_ledger_blocks_no_truncate BEFORE TRUNCATE ON legacy_ledger_blocks
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS legacy_ledger_blocks;
            DROP FUNCTION IF EXISTS legacy_ledger_blocks_chain();
        SQL);
    }
};
