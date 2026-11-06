<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accent- and case-insensitive search over who did it and what was done, through a
     * trigram index (same pattern as members.search_name). It is a derived column: it is not
     * part of the hashed content, so adding it does not touch the chain.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE activity_log
                ADD COLUMN search_text text GENERATED ALWAYS AS (
                    lower(immutable_unaccent(coalesce(actor_name, '') || ' ' || summary))
                ) STORED;

            CREATE INDEX activity_log_search_text_index ON activity_log USING gin (search_text gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS activity_log_search_text_index;
            ALTER TABLE activity_log DROP COLUMN IF EXISTS search_text;
        SQL);
    }
};
