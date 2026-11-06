<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The chain trigger also stamps occurred_at, with the real clock, under the same lock that
     * assigns seq:
     *
     * - now() is the start of the transaction, so several facts written by one operation all
     *   carried the same instant and their order was lost. clock_timestamp() is the moment of
     *   the insert, so time order and chain order agree.
     * - The application can no longer write a date of its choosing into the log. Only a row
     *   imported from the old system (it has a legacy_id) keeps its original date.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous char(64);
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('activity_log_chain'));
                IF NEW.legacy_id IS NULL THEN
                    NEW.occurred_at := clock_timestamp();
                END IF;
                NEW.seq := nextval('activity_log_seq');
                SELECT hash INTO previous FROM activity_log ORDER BY seq DESC LIMIT 1;
                NEW.previous_hash := previous;
                NEW.hash := activity_log_hash(previous, NEW);
                RETURN NEW;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous char(64);
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('activity_log_chain'));
                NEW.seq := nextval('activity_log_seq');
                SELECT hash INTO previous FROM activity_log ORDER BY seq DESC LIMIT 1;
                NEW.previous_hash := previous;
                NEW.hash := activity_log_hash(previous, NEW);
                RETURN NEW;
            END
            $$;
        SQL);
    }
};
