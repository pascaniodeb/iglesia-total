<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The journal in double entry (adapted from safipuv-v4 to a single congregation):
     *
     * - journal_entries: one entry per economic fact, idempotent by (origin_type, origin_id,
     *   origin_purpose): the same fact can never be posted twice.
     * - journal_entry_lines: a debit or a credit in numeric(18,2), in one currency.
     * - Deferred constraint triggers, checked at commit (or when the application asks for
     *   them): at least two lines and as many as lines_count; per currency, debits = credits
     *   and more than zero; a reversal inverts its original exactly. An unbalanced entry cannot
     *   be committed by anyone, the application included.
     * - Lines can only use active accounts.
     * - Append-only: a mistake is corrected with a reversal (and its reason), never by editing
     *   or deleting.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE journal_entries (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                entry_date date NOT NULL,
                month char(7) NOT NULL,
                description varchar(255) NOT NULL,
                origin_type varchar(40) NOT NULL,
                origin_id varchar(64) NOT NULL,
                origin_purpose varchar(40) NOT NULL,
                reversal_of uuid NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                reversal_reason varchar(255) NULL,
                lines_count smallint NOT NULL,
                created_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT journal_entries_origin_unique UNIQUE (origin_type, origin_id, origin_purpose),
                CONSTRAINT journal_entries_reversal_unique UNIQUE (reversal_of),
                CONSTRAINT journal_entries_month_format CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT journal_entries_month_matches_date CHECK (to_char(entry_date, 'YYYY-MM') = month),
                CONSTRAINT journal_entries_description_not_blank CHECK (btrim(description) <> ''),
                CONSTRAINT journal_entries_origin_format CHECK (
                    origin_type ~ '^[a-z0-9]+(_[a-z0-9]+)*$' AND origin_purpose ~ '^[a-z0-9]+(_[a-z0-9]+)*$' AND btrim(origin_id) <> ''
                ),
                CONSTRAINT journal_entries_lines_count CHECK (lines_count >= 2),
                CONSTRAINT journal_entries_reversal_reason CHECK (
                    (reversal_of IS NULL AND reversal_reason IS NULL)
                    OR (reversal_of IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
                ),
                CONSTRAINT journal_entries_reversal_self CHECK (reversal_of IS NULL OR reversal_of <> id)
            );

            CREATE INDEX journal_entries_month_index ON journal_entries (month, entry_date, id);
            CREATE INDEX journal_entries_created_by_index ON journal_entries (created_by);

            CREATE TABLE journal_entry_lines (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                journal_entry_id uuid NOT NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                line_number smallint NOT NULL,
                account_id uuid NOT NULL REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
                currency char(3) NOT NULL,
                debit numeric(18, 2) NOT NULL DEFAULT 0,
                credit numeric(18, 2) NOT NULL DEFAULT 0,
                description varchar(255) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT journal_entry_lines_entry_line_unique UNIQUE (journal_entry_id, line_number),
                CONSTRAINT journal_entry_lines_line_number CHECK (line_number > 0),
                CONSTRAINT journal_entry_lines_currency CHECK (currency IN ('VES', 'USD', 'COP')),
                CONSTRAINT journal_entry_lines_amounts CHECK (debit >= 0 AND credit >= 0),
                CONSTRAINT journal_entry_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
                CONSTRAINT journal_entry_lines_description CHECK (description IS NULL OR btrim(description) <> '')
            );

            CREATE INDEX journal_entry_lines_account_index ON journal_entry_lines (account_id, currency, journal_entry_id);

            CREATE OR REPLACE FUNCTION journal_entry_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT (SELECT is_active FROM accounting_accounts WHERE id = NEW.account_id) THEN
                    RAISE EXCEPTION 'Nothing can be posted to an inactive account'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'journal_entry_lines_active_account';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER journal_entry_lines_guard BEFORE INSERT ON journal_entry_lines
                FOR EACH ROW EXECUTE FUNCTION journal_entry_lines_guard();

            CREATE OR REPLACE FUNCTION journal_entry_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                e journal_entries%ROWTYPE;
                original journal_entries%ROWTYPE;
                k uuid;
                n bigint;
                unbalanced bigint;
                mismatch bigint;
            BEGIN
                IF TG_TABLE_NAME = 'journal_entries' THEN
                    k := NEW.id;
                ELSE
                    k := NEW.journal_entry_id;
                END IF;

                SELECT * INTO STRICT e FROM journal_entries WHERE id = k;
                SELECT count(*) INTO n FROM journal_entry_lines WHERE journal_entry_id = k;

                IF n < 2 OR n <> e.lines_count THEN
                    RAISE EXCEPTION 'A journal entry needs at least two lines and exactly lines_count lines'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'journal_entry_complete';
                END IF;

                SELECT count(*) INTO unbalanced FROM (
                    SELECT currency FROM journal_entry_lines
                    WHERE journal_entry_id = k
                    GROUP BY currency
                    HAVING sum(debit) <> sum(credit) OR sum(debit) <= 0
                ) u;

                IF unbalanced > 0 THEN
                    RAISE EXCEPTION 'The journal entry does not balance in every currency: debits must equal credits'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'journal_entry_complete';
                END IF;

                IF e.reversal_of IS NOT NULL THEN
                    SELECT * INTO STRICT original FROM journal_entries WHERE id = e.reversal_of;

                    SELECT count(*) INTO mismatch FROM (
                        SELECT account_id, currency, sum(debit) AS d, sum(credit) AS c
                        FROM journal_entry_lines WHERE journal_entry_id = original.id
                        GROUP BY account_id, currency
                    ) o
                    FULL OUTER JOIN (
                        SELECT account_id, currency, sum(debit) AS d, sum(credit) AS c
                        FROM journal_entry_lines WHERE journal_entry_id = e.id
                        GROUP BY account_id, currency
                    ) r ON r.account_id = o.account_id AND r.currency = o.currency
                    WHERE o.account_id IS NULL OR r.account_id IS NULL OR o.d <> r.c OR o.c <> r.d;

                    IF mismatch > 0 OR original.lines_count <> e.lines_count THEN
                        RAISE EXCEPTION 'A reversal must invert exactly the debits and credits of its original entry'
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'journal_entry_reversal_mirror';
                    END IF;
                END IF;

                RETURN NULL;
            END
            $$;

            CREATE CONSTRAINT TRIGGER journal_entries_complete
                AFTER INSERT ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_complete();

            CREATE CONSTRAINT TRIGGER journal_entry_lines_complete
                AFTER INSERT ON journal_entry_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_complete();
        SQL);

        foreach (['journal_entries', 'journal_entry_lines'] as $table) {
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
            DROP TABLE IF EXISTS journal_entry_lines;
            DROP TABLE IF EXISTS journal_entries;
            DROP FUNCTION IF EXISTS journal_entry_complete();
            DROP FUNCTION IF EXISTS journal_entry_lines_guard();
        SQL);
    }
};
