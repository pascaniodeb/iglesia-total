<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Weekly closures (Treasury, step 1 of the month circuit). A closure snapshots the count
     * and the totals per currency of the week's live offerings and locks the week forever (D7:
     * a closed week never reopens).
     *
     * PostgreSQL guarantees it, for every role:
     * - On insert, the snapshot is recomputed from the offerings and must match to the cent;
     *   a closure cannot record a total the books do not have.
     * - Once a week is closed, no offering of that week can be inserted, corrected or sent to
     *   the trash, and none can be moved into or out of it.
     * - Closures are append-only.
     *
     * iso_week_key() is the same expression as offerings.week_key, as a function so the
     * triggers (which run before generated columns are computed) use exactly the same rule.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION iso_week_key(d date) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
                SELECT extract(isoyear FROM d)::int::text || '-W' || lpad(extract(week FROM d)::int::text, 2, '0')
            $$;

            CREATE TABLE weekly_closures (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                week_key varchar(8) NOT NULL,
                week_start date NOT NULL,
                week_end date NOT NULL,
                offerings_count integer NOT NULL,
                total_ves numeric(18, 2) NOT NULL,
                total_usd numeric(18, 2) NOT NULL,
                total_cop numeric(18, 2) NOT NULL,
                closed_at timestamptz NOT NULL DEFAULT now(),
                closed_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT weekly_closures_week_key_unique UNIQUE (week_key),
                CONSTRAINT weekly_closures_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT weekly_closures_week_key_format CHECK (week_key ~ '^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$'),
                CONSTRAINT weekly_closures_monday CHECK (extract(isodow FROM week_start) = 1),
                CONSTRAINT weekly_closures_seven_days CHECK (week_end = week_start + 6),
                CONSTRAINT weekly_closures_key_matches_start CHECK (iso_week_key(week_start) = week_key),
                CONSTRAINT weekly_closures_count CHECK (offerings_count >= 0),
                CONSTRAINT weekly_closures_totals CHECK (total_ves >= 0 AND total_usd >= 0 AND total_cop >= 0)
            );

            CREATE INDEX weekly_closures_week_start_index ON weekly_closures (week_start DESC);
            CREATE INDEX weekly_closures_closed_by_index ON weekly_closures (closed_by);

            CREATE OR REPLACE FUNCTION weekly_closures_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                live record;
            BEGIN
                SELECT count(*) AS n,
                       coalesce(sum(amount_ves), 0) AS ves,
                       coalesce(sum(amount_usd), 0) AS usd,
                       coalesce(sum(amount_cop), 0) AS cop
                INTO live
                FROM offerings
                WHERE week_key = NEW.week_key AND deleted_at IS NULL;

                IF live.n <> NEW.offerings_count OR live.ves <> NEW.total_ves OR live.usd <> NEW.total_usd OR live.cop <> NEW.total_cop THEN
                    RAISE EXCEPTION 'The closure of % does not match its offerings (% offerings, VES %, USD %, COP %)', NEW.week_key, live.n, live.ves, live.usd, live.cop
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'weekly_closures_snapshot';
                END IF;

                NEW.closed_at := clock_timestamp();
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER weekly_closures_snapshot BEFORE INSERT ON weekly_closures
                FOR EACH ROW EXECUTE FUNCTION weekly_closures_snapshot();
            CREATE TRIGGER weekly_closures_no_update BEFORE UPDATE ON weekly_closures
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER weekly_closures_no_delete BEFORE DELETE ON weekly_closures
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER weekly_closures_no_truncate BEFORE TRUNCATE ON weekly_closures
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();

            CREATE OR REPLACE FUNCTION offerings_closed_week_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM weekly_closures WHERE week_key = iso_week_key(NEW.offering_date))
                    OR (TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM weekly_closures WHERE week_key = iso_week_key(OLD.offering_date))) THEN
                    RAISE EXCEPTION 'The week of this offering is closed: it cannot change'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'offerings_closed_week';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER offerings_closed_week_guard BEFORE INSERT OR UPDATE ON offerings
                FOR EACH ROW EXECUTE FUNCTION offerings_closed_week_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS offerings_closed_week_guard ON offerings;
            DROP FUNCTION IF EXISTS offerings_closed_week_guard();
            DROP TABLE IF EXISTS weekly_closures;
            DROP FUNCTION IF EXISTS weekly_closures_snapshot();
            DROP FUNCTION IF EXISTS iso_week_key(date);
        SQL);
    }
};
