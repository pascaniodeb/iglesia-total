<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Row seals: an HMAC-SHA256, made with a key that never lives in the database, over the canonical
     * form of a row of a sealed model (offerings, receipts, closures, journal entries and lines). The
     * database's own guards (append-only triggers, closed-week guards) stop the application and
     * ordinary SQL; a seal also stops someone who can switch triggers off: they can rewrite a row but
     * cannot forge a valid seal for it without the key, so `ledger:verify` finds the change.
     *
     * A row can be sealed many times (an offering is corrected while its week is open): the latest
     * seal is the one that must match. Append-only like everything that vouches for something.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE row_seals (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                seq bigint GENERATED ALWAYS AS IDENTITY,
                table_name varchar(63) NOT NULL,
                row_id text NOT NULL,
                seal char(64) NOT NULL,
                sealed_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT row_seals_seq_unique UNIQUE (seq),
                CONSTRAINT row_seals_seal_hex CHECK (seal ~ '^[0-9a-f]{64}$')
            );
            CREATE INDEX row_seals_row_idx ON row_seals (table_name, row_id, seq DESC);

            CREATE TRIGGER row_seals_no_update BEFORE UPDATE ON row_seals
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER row_seals_no_delete BEFORE DELETE ON row_seals
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER row_seals_no_truncate BEFORE TRUNCATE ON row_seals
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS row_seals;');
    }
};
