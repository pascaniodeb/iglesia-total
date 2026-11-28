<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archive of the old system's activity log: its 203 entries copied verbatim (who, when, what,
     * the before and after), so what was done before the cut-over stays readable. The old log had no
     * hash chain, so it cannot prove itself; instead the import records, in the NEW chained log, the
     * digest of what was archived, and a check of the integrity center recomputes that digest.
     *
     * Append-only: UPDATE, DELETE and TRUNCATE are rejected. The author is the old system's integer
     * id plus the name and role as they were then.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE legacy_activity_log (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NOT NULL,
                occurred_at timestamptz NOT NULL,
                legacy_actor_id integer NULL,
                actor_name text NOT NULL,
                actor_role text NOT NULL,
                action text NOT NULL,
                entity text NOT NULL,
                entity_id text NULL,
                summary text NOT NULL,
                before jsonb NULL,
                after jsonb NULL,
                diff jsonb NULL,
                ip text NULL,
                user_agent text NULL,
                archived_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT legacy_activity_log_legacy_id_unique UNIQUE (legacy_id)
            );
            CREATE INDEX legacy_activity_log_occurred_idx ON legacy_activity_log (occurred_at DESC);

            CREATE TRIGGER legacy_activity_log_no_update BEFORE UPDATE ON legacy_activity_log
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER legacy_activity_log_no_delete BEFORE DELETE ON legacy_activity_log
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER legacy_activity_log_no_truncate BEFORE TRUNCATE ON legacy_activity_log
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS legacy_activity_log;');
    }
};
