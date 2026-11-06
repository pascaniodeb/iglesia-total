<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The exchange rates of each day (Treasury), exactly as safipuv-v4 keeps them: one row per
     * Venezuelan date. The sync with the official sources updates it during the day; a manual
     * correction prevails (the sync never overwrites it). Rows are never deleted: they are
     * corrected, and every change is recorded in the activity log with its previous value.
     *
     * A rate only informs, compares and sorts: the books are kept in each currency and no entry,
     * close or balance is ever computed from a rate.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE exchange_rates (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                rate_date date NOT NULL,
                usd_rate numeric(14, 4) NOT NULL,
                cop_rate numeric(16, 6) NOT NULL,
                eur_rate numeric(14, 4) NULL,
                trm_cop_usd numeric(14, 4) NULL,
                origin varchar(10) NOT NULL,
                legacy_id varchar(26) NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT exchange_rates_date_unique UNIQUE (rate_date),
                CONSTRAINT exchange_rates_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT exchange_rates_positive CHECK (
                    usd_rate > 0 AND cop_rate > 0 AND coalesce(eur_rate, 1) > 0 AND coalesce(trm_cop_usd, 1) > 0
                ),
                CONSTRAINT exchange_rates_origin_check CHECK (origin IN ('market', 'manual', 'legacy')),
                CONSTRAINT exchange_rates_market_complete CHECK (origin <> 'market' OR (eur_rate IS NOT NULL AND trm_cop_usd IS NOT NULL))
            );

            CREATE INDEX exchange_rates_date_index ON exchange_rates (rate_date DESC);

            CREATE TRIGGER exchange_rates_no_delete BEFORE DELETE ON exchange_rates
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER exchange_rates_no_truncate BEFORE TRUNCATE ON exchange_rates
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS exchange_rates');
    }
};
