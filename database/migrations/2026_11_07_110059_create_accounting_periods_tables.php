<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accounting periods (step 4 of the month circuit). A row means the month is closed forever
     * (D7): its books can no longer receive entries.
     *
     * - Months close in order, without gaps.
     * - accounting_period_balances is the immutable snapshot of every account and currency at
     *   the end of the month (cumulative debits, credits and the balance on the account's
     *   normal side). At commit PostgreSQL checks it against the journal lines, to the cent and
     *   without a missing or an extra account.
     * - No journal entry can be inserted into a closed month.
     * - Both tables are append-only. The opening balance of a month is never written: it is the
     *   previous month's closing (D3).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE accounting_periods (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                month char(7) NOT NULL,
                closed_at timestamptz NOT NULL DEFAULT now(),
                closed_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_month_unique UNIQUE (month),
                CONSTRAINT accounting_periods_month_format CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')
            );

            CREATE INDEX accounting_periods_closed_by_index ON accounting_periods (closed_by);

            CREATE TABLE accounting_period_balances (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                accounting_period_id uuid NOT NULL REFERENCES accounting_periods (id) ON DELETE RESTRICT,
                account_id uuid NOT NULL REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
                currency char(3) NOT NULL,
                total_debit numeric(18, 2) NOT NULL,
                total_credit numeric(18, 2) NOT NULL,
                balance numeric(18, 2) NOT NULL,
                CONSTRAINT accounting_period_balances_unique UNIQUE (accounting_period_id, account_id, currency),
                CONSTRAINT accounting_period_balances_currency CHECK (currency IN ('VES', 'USD', 'COP')),
                CONSTRAINT accounting_period_balances_totals CHECK (total_debit >= 0 AND total_credit >= 0)
            );

            CREATE INDEX accounting_period_balances_account_index ON accounting_period_balances (account_id, currency);

            CREATE OR REPLACE FUNCTION accounting_periods_order() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                last_month char(7);
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('accounting_periods'));
                SELECT max(month) INTO last_month FROM accounting_periods;

                IF last_month IS NOT NULL AND NEW.month <> to_char((last_month || '-01')::date + interval '1 month', 'YYYY-MM') THEN
                    RAISE EXCEPTION 'Months close in order: after % comes %', last_month, to_char((last_month || '-01')::date + interval '1 month', 'YYYY-MM')
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'accounting_periods_order';
                END IF;

                NEW.closed_at := clock_timestamp();
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER accounting_periods_order BEFORE INSERT ON accounting_periods
                FOR EACH ROW EXECUTE FUNCTION accounting_periods_order();

            CREATE OR REPLACE FUNCTION accounting_period_balances_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                k uuid;
                period accounting_periods%ROWTYPE;
                last_day date;
                mismatches bigint;
            BEGIN
                IF TG_TABLE_NAME = 'accounting_periods' THEN
                    k := NEW.id;
                ELSE
                    k := NEW.accounting_period_id;
                END IF;

                SELECT * INTO STRICT period FROM accounting_periods WHERE id = k;
                last_day := ((period.month || '-01')::date + interval '1 month' - interval '1 day')::date;

                SELECT count(*) INTO mismatches
                FROM (
                    SELECT l.account_id, l.currency, sum(l.debit) AS d, sum(l.credit) AS c,
                           CASE WHEN a.type IN ('asset', 'expense') THEN sum(l.debit) - sum(l.credit) ELSE sum(l.credit) - sum(l.debit) END AS b
                    FROM journal_entry_lines l
                    JOIN journal_entries e ON e.id = l.journal_entry_id
                    JOIN accounting_accounts a ON a.id = l.account_id
                    WHERE e.entry_date <= last_day
                    GROUP BY l.account_id, l.currency, a.type
                ) live
                FULL OUTER JOIN (
                    SELECT account_id, currency, total_debit AS d, total_credit AS c, balance AS b
                    FROM accounting_period_balances WHERE accounting_period_id = k
                ) snap ON snap.account_id = live.account_id AND snap.currency = live.currency
                WHERE live.account_id IS NULL OR snap.account_id IS NULL
                   OR live.d <> snap.d OR live.c <> snap.c OR live.b <> snap.b;

                IF mismatches > 0 THEN
                    RAISE EXCEPTION 'The balances of % do not match the journal (% accounts differ)', period.month, mismatches
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'accounting_period_balances_complete';
                END IF;

                RETURN NULL;
            END
            $$;

            CREATE CONSTRAINT TRIGGER accounting_periods_complete
                AFTER INSERT ON accounting_periods
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION accounting_period_balances_complete();
            CREATE CONSTRAINT TRIGGER accounting_period_balances_complete
                AFTER INSERT ON accounting_period_balances
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION accounting_period_balances_complete();

            CREATE OR REPLACE FUNCTION journal_entries_open_month() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM accounting_periods WHERE month >= NEW.month) THEN
                    RAISE EXCEPTION 'The month % is closed: it cannot receive entries', NEW.month
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'journal_entries_open_month';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER journal_entries_open_month BEFORE INSERT ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_entries_open_month();
        SQL);

        foreach (['accounting_periods', 'accounting_period_balances'] as $table) {
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
            DROP TRIGGER IF EXISTS journal_entries_open_month ON journal_entries;
            DROP FUNCTION IF EXISTS journal_entries_open_month();
            DROP TABLE IF EXISTS accounting_period_balances;
            DROP TABLE IF EXISTS accounting_periods;
            DROP FUNCTION IF EXISTS accounting_period_balances_complete();
            DROP FUNCTION IF EXISTS accounting_periods_order();
        SQL);
    }
};
