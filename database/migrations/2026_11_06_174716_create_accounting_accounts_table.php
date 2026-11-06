<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The chart of accounts. One chart for the congregation, accounts without currency: every
     * journal line carries its own currency and balances are kept per account and currency.
     *
     * - Income (I-xxx) and expense (E-xxx) codes are the IPUV codes printed on the reports.
     * - System accounts have a function the posting logic relies on and are created here:
     *   CAJA (cash), I-001 SALDO ANTERIOR (the opening balance carried from the previous books)
     *   and PUENTE CAMBIARIO (the bridge a currency exchange goes through, so each currency of
     *   the exchange entry balances on its own).
     * - Accounts are never deleted (an entry may point to them) and their code, type and
     *   function never change once created: a report from last year must still mean the same.
     *   Name and active flag can change.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE accounting_accounts (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                code varchar(10) NOT NULL,
                name varchar(160) NOT NULL,
                type varchar(20) NOT NULL,
                system_function varchar(30) NULL,
                is_active boolean NOT NULL DEFAULT true,
                search_text text GENERATED ALWAYS AS (lower(immutable_unaccent(code || ' ' || name))) STORED,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT accounting_accounts_code_unique UNIQUE (code),
                CONSTRAINT accounting_accounts_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT accounting_accounts_system_function_unique UNIQUE (system_function),
                CONSTRAINT accounting_accounts_code_format CHECK (code ~ '^[A-Z]-[0-9]{3}$'),
                CONSTRAINT accounting_accounts_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT accounting_accounts_type_check CHECK (type IN ('asset', 'equity', 'income', 'expense')),
                CONSTRAINT accounting_accounts_system_function_check CHECK (
                    system_function IS NULL
                    OR (system_function = 'cash' AND type = 'asset')
                    OR (system_function = 'opening_balance' AND type = 'equity')
                    OR (system_function = 'currency_exchange' AND type = 'asset')
                ),
                CONSTRAINT accounting_accounts_system_active CHECK (system_function IS NULL OR is_active),
                CONSTRAINT accounting_accounts_code_matches_type CHECK (
                    system_function IS NOT NULL
                    OR (type = 'income' AND code LIKE 'I-%')
                    OR (type = 'expense' AND code LIKE 'E-%')
                )
            );

            CREATE INDEX accounting_accounts_search_text_index ON accounting_accounts USING gin (search_text gin_trgm_ops);

            CREATE OR REPLACE FUNCTION accounting_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code
                    OR NEW.type IS DISTINCT FROM OLD.type
                    OR NEW.system_function IS DISTINCT FROM OLD.system_function THEN
                    RAISE EXCEPTION 'The code, type and function of an account never change'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'accounting_accounts_identity';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER accounting_accounts_guard BEFORE UPDATE ON accounting_accounts
                FOR EACH ROW EXECUTE FUNCTION accounting_accounts_guard();
            CREATE TRIGGER accounting_accounts_no_delete BEFORE DELETE ON accounting_accounts
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER accounting_accounts_no_truncate BEFORE TRUNCATE ON accounting_accounts
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();

            INSERT INTO accounting_accounts (code, name, type, system_function, created_at, updated_at) VALUES
                ('A-001', 'CAJA', 'asset', 'cash', now(), now()),
                ('A-002', 'PUENTE CAMBIARIO', 'asset', 'currency_exchange', now(), now()),
                ('I-001', 'SALDO ANTERIOR', 'equity', 'opening_balance', now(), now());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS accounting_accounts;
            DROP FUNCTION IF EXISTS accounting_accounts_guard();
        SQL);
    }
};
