<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trigger functions shared by every append-only table (activity log, sealed ledger,
     * journal, period snapshots). A trigger fires for every role that writes, the
     * application's included, so an UPDATE or DELETE issued by mistake (or by SQL typed by
     * hand) is rejected by PostgreSQL itself instead of trusting the code not to try.
     *
     * Honest limit: the table owner can disable triggers. That is why the chain heads are
     * printed on paper and anchored outside the database.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: rows cannot be changed', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$;

            CREATE OR REPLACE FUNCTION forbid_physical_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: rows cannot be deleted', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS forbid_update();
            DROP FUNCTION IF EXISTS forbid_physical_delete();
        SQL);
    }
};
