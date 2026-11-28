<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * External anchors of the two chains (the sealed ledger and the activity log). The chain proves
     * that nobody touched a block without touching the following ones; it proves nothing against
     * someone who can rewrite the whole table. An anchor publishes a digest of the head (never data)
     * in a system nobody in the church controls: a timestamp authority (RFC 3161) and Bitcoin
     * (OpenTimestamps). The anchored statement is plain text and its digest the SHA-256 of that text,
     * so it can be checked with the official tools (`ots verify`, `openssl ts -verify`).
     *
     * A pending proof can only become confirmed; nothing else changes and nothing is deleted.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE chain_anchors (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                chain varchar(20) NOT NULL,
                provider varchar(30) NOT NULL,
                position bigint NOT NULL,
                hash char(64) NOT NULL,
                statement text NOT NULL,
                digest char(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                proof jsonb NOT NULL,
                proven_at timestamptz NULL,
                confirmed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT chain_anchors_unique UNIQUE (chain, provider, position),
                CONSTRAINT chain_anchors_chain_check CHECK (chain IN ('ledger', 'activity')),
                CONSTRAINT chain_anchors_status_check CHECK (status IN ('pending', 'confirmed')),
                CONSTRAINT chain_anchors_confirmed_complete CHECK ((status = 'confirmed') = (confirmed_at IS NOT NULL)),
                CONSTRAINT chain_anchors_hash_hex CHECK (hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chain_anchors_digest_matches CHECK (digest = encode(sha256(convert_to(statement, 'UTF8')), 'hex'))
            );
            CREATE INDEX chain_anchors_status_idx ON chain_anchors (status, chain, position);

            CREATE OR REPLACE FUNCTION chain_anchors_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF old.status <> 'pending' OR new.status <> 'confirmed'
                    OR (to_jsonb(new) - 'status' - 'proof' - 'proven_at' - 'confirmed_at')
                       <> (to_jsonb(old) - 'status' - 'proof' - 'proven_at' - 'confirmed_at')
                THEN
                    RAISE EXCEPTION 'An anchor only goes from pending to confirmed' USING ERRCODE = 'check_violation';
                END IF;

                RETURN new;
            END
            $$;

            CREATE TRIGGER chain_anchors_guard BEFORE UPDATE ON chain_anchors
                FOR EACH ROW EXECUTE FUNCTION chain_anchors_guard();
            CREATE TRIGGER chain_anchors_no_delete BEFORE DELETE ON chain_anchors
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER chain_anchors_no_truncate BEFORE TRUNCATE ON chain_anchors
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS chain_anchors;
            DROP FUNCTION IF EXISTS chain_anchors_guard();
        SQL);
    }
};
