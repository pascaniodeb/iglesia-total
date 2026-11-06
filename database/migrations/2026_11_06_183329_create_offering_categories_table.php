<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Offering categories (Treasury). Each one says to which income account its offerings go
     * when the month's offerings are posted to the journal.
     *
     * The account is NOT NULL on purpose: a category without an account is exactly how the
     * earlier system lost money silently at month end. It must be an income account, checked by
     * the database when it is assigned. Categories are never deleted (offerings point to them);
     * they are deactivated.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE offering_categories (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                name varchar(120) NOT NULL,
                account_id uuid NOT NULL REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT offering_categories_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT offering_categories_name_not_blank CHECK (btrim(name) <> '')
            );

            CREATE UNIQUE INDEX offering_categories_name_unique ON offering_categories (lower(immutable_unaccent(name)));
            CREATE INDEX offering_categories_account_id_index ON offering_categories (account_id);

            CREATE OR REPLACE FUNCTION offering_categories_income_account() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT type FROM accounting_accounts WHERE id = NEW.account_id) IS DISTINCT FROM 'income' THEN
                    RAISE EXCEPTION 'An offering category must point to an income account'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'offering_categories_income_account';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER offering_categories_income_account BEFORE INSERT OR UPDATE OF account_id ON offering_categories
                FOR EACH ROW EXECUTE FUNCTION offering_categories_income_account();
            CREATE TRIGGER offering_categories_no_delete BEFORE DELETE ON offering_categories
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER offering_categories_no_truncate BEFORE TRUNCATE ON offering_categories
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS offering_categories;
            DROP FUNCTION IF EXISTS offering_categories_income_account();
        SQL);
    }
};
