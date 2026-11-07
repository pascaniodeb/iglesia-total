<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Monthly offering closures (Treasury, step 2 of the month circuit). A month's offerings are
     * snapshotted by category (and the income account each category points to) and posted to
     * the journal as one income entry: debit CAJA, credit each income account, per currency.
     *
     * PostgreSQL guarantees it, for every role:
     * - Every ISO week with at least one day in the month is closed, from the first closed week on
     *   (weeks before the circuit started, i.e. before the first weekly closure, do not exist in
     *   it and are not required: otherwise the first month could never close).
     * - The header's count and totals are the live offerings of the month, to the cent.
     * - The posted entry debits CAJA by exactly the header's totals, per currency; a month
     *   without offerings has no entry.
     * - At commit, the lines (one per category) add up to the header.
     * - Both tables are append-only: a month's offerings are closed once and never reopen.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE monthly_offering_closures (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                month char(7) NOT NULL,
                offerings_count integer NOT NULL,
                total_ves numeric(18, 2) NOT NULL,
                total_usd numeric(18, 2) NOT NULL,
                total_cop numeric(18, 2) NOT NULL,
                journal_entry_id uuid NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                closed_at timestamptz NOT NULL DEFAULT now(),
                closed_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT monthly_offering_closures_month_unique UNIQUE (month),
                CONSTRAINT monthly_offering_closures_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT monthly_offering_closures_entry_unique UNIQUE (journal_entry_id),
                CONSTRAINT monthly_offering_closures_month_format CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT monthly_offering_closures_count CHECK (offerings_count >= 0),
                CONSTRAINT monthly_offering_closures_totals CHECK (total_ves >= 0 AND total_usd >= 0 AND total_cop >= 0),
                CONSTRAINT monthly_offering_closures_entry_when_money CHECK ((offerings_count = 0) = (journal_entry_id IS NULL))
            );

            CREATE INDEX monthly_offering_closures_closed_by_index ON monthly_offering_closures (closed_by);

            CREATE TABLE monthly_offering_closure_lines (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                monthly_offering_closure_id uuid NOT NULL REFERENCES monthly_offering_closures (id) ON DELETE RESTRICT,
                offering_category_id uuid NOT NULL REFERENCES offering_categories (id) ON DELETE RESTRICT,
                account_id uuid NOT NULL REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
                offerings_count integer NOT NULL,
                total_ves numeric(18, 2) NOT NULL,
                total_usd numeric(18, 2) NOT NULL,
                total_cop numeric(18, 2) NOT NULL,
                CONSTRAINT monthly_offering_closure_lines_category_unique UNIQUE (monthly_offering_closure_id, offering_category_id),
                CONSTRAINT monthly_offering_closure_lines_count CHECK (offerings_count > 0),
                CONSTRAINT monthly_offering_closure_lines_totals CHECK (total_ves >= 0 AND total_usd >= 0 AND total_cop >= 0)
            );

            CREATE INDEX monthly_offering_closure_lines_category_index ON monthly_offering_closure_lines (offering_category_id);
            CREATE INDEX monthly_offering_closure_lines_account_index ON monthly_offering_closure_lines (account_id);

            CREATE OR REPLACE FUNCTION monthly_offering_closures_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                first_day date := (NEW.month || '-01')::date;
                last_day date := ((NEW.month || '-01')::date + interval '1 month' - interval '1 day')::date;
                open_weeks text;
                live record;
                cash_mismatch bigint;
            BEGIN
                SELECT string_agg(k, ', ' ORDER BY k) INTO open_weeks
                FROM (
                    SELECT DISTINCT iso_week_key(d::date) AS k, date_trunc('week', d)::date AS starts_on
                    FROM generate_series(first_day, last_day, interval '1 day') AS d
                ) weeks
                WHERE NOT EXISTS (SELECT 1 FROM weekly_closures w WHERE w.week_key = weeks.k)
                  AND weeks.starts_on >= coalesce((SELECT min(week_start) FROM weekly_closures), '-infinity'::date);

                IF open_weeks IS NOT NULL THEN
                    RAISE EXCEPTION 'The month % still has open weeks: %', NEW.month, open_weeks
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'monthly_offering_closures_weeks';
                END IF;

                SELECT count(*) AS n,
                       coalesce(sum(amount_ves), 0) AS ves,
                       coalesce(sum(amount_usd), 0) AS usd,
                       coalesce(sum(amount_cop), 0) AS cop
                INTO live
                FROM offerings
                WHERE month_key = NEW.month AND deleted_at IS NULL;

                IF live.n <> NEW.offerings_count OR live.ves <> NEW.total_ves OR live.usd <> NEW.total_usd OR live.cop <> NEW.total_cop THEN
                    RAISE EXCEPTION 'The offering closure of % does not match its offerings (% offerings, VES %, USD %, COP %)', NEW.month, live.n, live.ves, live.usd, live.cop
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'monthly_offering_closures_snapshot';
                END IF;

                IF NEW.journal_entry_id IS NOT NULL THEN
                    SELECT count(*) INTO cash_mismatch
                    FROM (VALUES ('VES', NEW.total_ves), ('USD', NEW.total_usd), ('COP', NEW.total_cop)) AS t(currency, total)
                    WHERE t.total <> coalesce((
                        SELECT sum(l.debit) - sum(l.credit)
                        FROM journal_entry_lines l
                        JOIN accounting_accounts a ON a.id = l.account_id
                        WHERE l.journal_entry_id = NEW.journal_entry_id
                          AND a.system_function = 'cash'
                          AND l.currency = t.currency
                    ), 0);

                    IF cash_mismatch > 0 THEN
                        RAISE EXCEPTION 'The entry of the offering closure of % does not post its totals to CAJA', NEW.month
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'monthly_offering_closures_entry';
                    END IF;
                END IF;

                NEW.closed_at := clock_timestamp();
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER monthly_offering_closures_guard BEFORE INSERT ON monthly_offering_closures
                FOR EACH ROW EXECUTE FUNCTION monthly_offering_closures_guard();

            CREATE OR REPLACE FUNCTION monthly_offering_closure_lines_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                k uuid;
                header monthly_offering_closures%ROWTYPE;
                sums record;
            BEGIN
                IF TG_TABLE_NAME = 'monthly_offering_closures' THEN
                    k := NEW.id;
                ELSE
                    k := NEW.monthly_offering_closure_id;
                END IF;

                SELECT * INTO STRICT header FROM monthly_offering_closures WHERE id = k;
                SELECT coalesce(sum(offerings_count), 0) AS n,
                       coalesce(sum(total_ves), 0) AS ves,
                       coalesce(sum(total_usd), 0) AS usd,
                       coalesce(sum(total_cop), 0) AS cop
                INTO sums
                FROM monthly_offering_closure_lines WHERE monthly_offering_closure_id = k;

                IF sums.n <> header.offerings_count OR sums.ves <> header.total_ves OR sums.usd <> header.total_usd OR sums.cop <> header.total_cop THEN
                    RAISE EXCEPTION 'The category lines of the offering closure of % do not add up to its totals', header.month
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'monthly_offering_closure_lines_complete';
                END IF;

                RETURN NULL;
            END
            $$;

            CREATE CONSTRAINT TRIGGER monthly_offering_closures_complete
                AFTER INSERT ON monthly_offering_closures
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION monthly_offering_closure_lines_complete();
            CREATE CONSTRAINT TRIGGER monthly_offering_closure_lines_complete
                AFTER INSERT ON monthly_offering_closure_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION monthly_offering_closure_lines_complete();
        SQL);

        foreach (['monthly_offering_closures', 'monthly_offering_closure_lines'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION forbid_update();
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
                CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                    FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS monthly_offering_closure_lines;
            DROP TABLE IF EXISTS monthly_offering_closures;
            DROP FUNCTION IF EXISTS monthly_offering_closure_lines_complete();
            DROP FUNCTION IF EXISTS monthly_offering_closures_guard();
        SQL);
    }
};
