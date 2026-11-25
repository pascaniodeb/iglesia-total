<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Receipts (comprobantes) of the IPUV paper book: one numbered series shared by income and
     * expense, each posted to the journal in the same transaction.
     *
     * - The number is assigned by the database, under a lock, as the next after the highest one
     *   (never below the book's start number): nobody can skip a number, repeat one or choose it.
     *   Raising the start number is how a new physical book begins; lowering it never reuses.
     * - A receipt is what was issued, so it is insert-only. Voiding is another fact, recorded in
     *   `receipt_voids` (also insert-only) together with the reversal entry that undoes its
     *   posting; the receipt keeps its number and its content forever.
     * - Before it is accepted, the database checks that the entry it points to posts exactly its
     *   amounts, to the cent, to CAJA and to its account, and that the account is of its type.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE receipt_settings (
                id smallint PRIMARY KEY DEFAULT 1,
                start_number integer NOT NULL DEFAULT 1,
                updated_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                updated_at timestamptz NULL,
                CONSTRAINT receipt_settings_singleton CHECK (id = 1),
                CONSTRAINT receipt_settings_start_positive CHECK (start_number > 0)
            );

            INSERT INTO receipt_settings (id, start_number) VALUES (1, 1);

            CREATE INDEX receipt_settings_updated_by_index ON receipt_settings (updated_by);

            CREATE TABLE receipts (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                number integer NOT NULL,
                type text NOT NULL,
                issued_on date NOT NULL,
                account_id uuid NOT NULL REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
                holder varchar(160) NULL,
                counterparty varchar(160) NULL,
                amount_ves numeric(18, 2) NOT NULL DEFAULT 0,
                amount_usd numeric(18, 2) NOT NULL DEFAULT 0,
                amount_cop numeric(18, 2) NOT NULL DEFAULT 0,
                concept varchar(1000) NULL,
                payment_method text NOT NULL,
                bank varchar(120) NULL,
                payment_reference varchar(120) NULL,
                payment_date date NULL,
                received_by_name varchar(160) NULL,
                received_by_id_number varchar(40) NULL,
                delivered_by_name varchar(160) NULL,
                delivered_by_id_number varchar(40) NULL,
                journal_entry_id uuid NOT NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                created_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT receipts_number_unique UNIQUE (number),
                CONSTRAINT receipts_journal_entry_unique UNIQUE (journal_entry_id),
                CONSTRAINT receipts_number_positive CHECK (number > 0),
                CONSTRAINT receipts_type CHECK (type IN ('income', 'expense')),
                CONSTRAINT receipts_payment_method CHECK (payment_method IN ('cash', 'deposit', 'transfer')),
                CONSTRAINT receipts_amounts CHECK (
                    amount_ves >= 0 AND amount_usd >= 0 AND amount_cop >= 0
                    AND (amount_ves > 0 OR amount_usd > 0 OR amount_cop > 0)
                ),
                CONSTRAINT receipts_text_not_blank CHECK (
                    (holder IS NULL OR btrim(holder) <> '')
                    AND (counterparty IS NULL OR btrim(counterparty) <> '')
                    AND (concept IS NULL OR btrim(concept) <> '')
                )
            );

            CREATE INDEX receipts_issued_on_index ON receipts (issued_on);
            CREATE INDEX receipts_account_id_index ON receipts (account_id);
            CREATE INDEX receipts_created_by_index ON receipts (created_by);

            CREATE OR REPLACE FUNCTION receipts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                start_at integer;
                highest integer;
                account_type text;
                account_function text;
                entry journal_entries%ROWTYPE;
                currencies integer;
                mismatch bigint;
            BEGIN
                -- One issuer at a time: the number is the next after the highest, so it cannot repeat.
                PERFORM pg_advisory_xact_lock(hashtext('receipts_number'));

                SELECT start_number INTO STRICT start_at FROM receipt_settings WHERE id = 1;
                SELECT max(number) INTO highest FROM receipts;
                NEW.number := greatest(start_at, coalesce(highest + 1, start_at));

                SELECT type::text, system_function::text INTO account_type, account_function
                FROM accounting_accounts WHERE id = NEW.account_id;

                IF account_type IS DISTINCT FROM NEW.type OR account_function IS NOT NULL THEN
                    RAISE EXCEPTION 'A % receipt must point to an % account', NEW.type, NEW.type
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'receipts_account_type';
                END IF;

                SELECT * INTO entry FROM journal_entries WHERE id = NEW.journal_entry_id;

                IF NOT FOUND OR entry.origin_type <> 'receipt' OR entry.origin_id <> NEW.id::text OR entry.entry_date <> NEW.issued_on THEN
                    RAISE EXCEPTION 'The entry of a receipt must be the one posted for it, on its date'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'receipts_entry_origin';
                END IF;

                currencies := (NEW.amount_ves > 0)::int + (NEW.amount_usd > 0)::int + (NEW.amount_cop > 0)::int;

                IF entry.lines_count <> 2 * currencies THEN
                    RAISE EXCEPTION 'The entry of a receipt must have one pair of lines per currency'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'receipts_entry_lines';
                END IF;

                -- Each currency: the cash moves by the receipt's amount, in its direction, and so does the account.
                SELECT count(*) INTO mismatch
                FROM (VALUES ('VES', NEW.amount_ves), ('USD', NEW.amount_usd), ('COP', NEW.amount_cop)) AS t(currency, amount)
                WHERE t.amount <> coalesce((
                        SELECT sum(CASE WHEN NEW.type = 'income' THEN l.debit - l.credit ELSE l.credit - l.debit END)
                        FROM journal_entry_lines l
                        JOIN accounting_accounts a ON a.id = l.account_id
                        WHERE l.journal_entry_id = NEW.journal_entry_id AND a.system_function = 'cash' AND l.currency = t.currency
                    ), 0)
                   OR t.amount <> coalesce((
                        SELECT sum(CASE WHEN NEW.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END)
                        FROM journal_entry_lines l
                        WHERE l.journal_entry_id = NEW.journal_entry_id AND l.account_id = NEW.account_id AND l.currency = t.currency
                    ), 0);

                IF mismatch > 0 THEN
                    RAISE EXCEPTION 'The entry of a receipt does not post its amounts to CAJA and to its account'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'receipts_entry_amounts';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER receipts_guard BEFORE INSERT ON receipts
                FOR EACH ROW EXECUTE FUNCTION receipts_guard();

            CREATE TABLE receipt_voids (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                receipt_id uuid NOT NULL REFERENCES receipts (id) ON DELETE RESTRICT,
                reason varchar(500) NOT NULL,
                reversal_entry_id uuid NOT NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                voided_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                voided_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT receipt_voids_receipt_unique UNIQUE (receipt_id),
                CONSTRAINT receipt_voids_reversal_unique UNIQUE (reversal_entry_id),
                CONSTRAINT receipt_voids_reason CHECK (char_length(btrim(reason)) >= 5)
            );

            CREATE INDEX receipt_voids_voided_by_index ON receipt_voids (voided_by);

            CREATE OR REPLACE FUNCTION receipt_voids_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM receipts r
                    JOIN journal_entries e ON e.id = NEW.reversal_entry_id
                    WHERE r.id = NEW.receipt_id AND e.reversal_of = r.journal_entry_id
                ) THEN
                    RAISE EXCEPTION 'The reversal of a voided receipt must be the reversal of its entry'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'receipt_voids_reversal';
                END IF;

                NEW.voided_at := clock_timestamp();
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER receipt_voids_guard BEFORE INSERT ON receipt_voids
                FOR EACH ROW EXECUTE FUNCTION receipt_voids_guard();

            CREATE TRIGGER receipt_settings_no_delete BEFORE DELETE ON receipt_settings
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER receipt_settings_no_truncate BEFORE TRUNCATE ON receipt_settings
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);

        foreach (['receipts', 'receipt_voids'] as $table) {
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
            DROP TABLE IF EXISTS receipt_voids;
            DROP TABLE IF EXISTS receipts;
            DROP TABLE IF EXISTS receipt_settings;
            DROP FUNCTION IF EXISTS receipt_voids_guard();
            DROP FUNCTION IF EXISTS receipts_guard();
        SQL);
    }
};
