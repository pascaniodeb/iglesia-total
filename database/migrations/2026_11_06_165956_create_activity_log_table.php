<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The activity log: who did what, on which record, when and from where.
     *
     * - Append-only: UPDATE, DELETE and TRUNCATE are rejected by triggers.
     * - Hash-chained: every row stores the hash of the previous row and its own hash,
     *   SHA-256(previous_hash || canonical row). PostgreSQL computes both in a BEFORE
     *   INSERT trigger under an advisory lock, so neither the application nor a
     *   concurrent insert can choose or break them. Changing or deleting a row directly in
     *   the database breaks the chain and `activity:verify` reports where.
     * - The actor's name and role are snapshots: the log must stay readable after a name
     *   or role changes. actor_id is RESTRICT because an append-only row cannot be set
     *   to NULL by a cascading foreign key.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE activity_log (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                seq bigint NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                category varchar(20) NOT NULL,
                channel varchar(20) NOT NULL,
                actor_id uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                actor_name varchar(255) NULL,
                actor_role varchar(40) NULL,
                action varchar(80) NOT NULL,
                subject_type varchar(80) NULL,
                subject_id varchar(64) NULL,
                summary varchar(500) NOT NULL,
                changes jsonb NOT NULL DEFAULT '{}',
                request_id uuid NULL,
                ip inet NULL,
                user_agent varchar(512) NULL,
                method varchar(10) NULL,
                route varchar(160) NULL,
                legacy_id integer NULL,
                previous_hash char(64) NULL,
                hash char(64) NOT NULL,
                CONSTRAINT activity_log_seq_unique UNIQUE (seq),
                CONSTRAINT activity_log_hash_unique UNIQUE (hash),
                CONSTRAINT activity_log_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT activity_log_category_check CHECK (category IN ('data', 'security', 'access', 'system')),
                CONSTRAINT activity_log_channel_check CHECK (channel IN ('web', 'system', 'guest')),
                CONSTRAINT activity_log_actor_matches_channel CHECK ((channel = 'web') = (actor_id IS NOT NULL)),
                CONSTRAINT activity_log_actor_snapshot CHECK (actor_id IS NULL OR actor_name IS NOT NULL),
                CONSTRAINT activity_log_subject_complete CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
                CONSTRAINT activity_log_summary_not_blank CHECK (btrim(summary) <> ''),
                CONSTRAINT activity_log_changes_object CHECK (jsonb_typeof(changes) = 'object')
            );

            CREATE SEQUENCE activity_log_seq OWNED BY activity_log.seq;

            CREATE INDEX activity_log_occurred_at_index ON activity_log (occurred_at DESC, id DESC);
            CREATE INDEX activity_log_actor_id_index ON activity_log (actor_id, occurred_at DESC);
            CREATE INDEX activity_log_subject_index ON activity_log (subject_type, subject_id);
            CREATE INDEX activity_log_category_index ON activity_log (category, occurred_at DESC);
            CREATE INDEX activity_log_request_id_index ON activity_log (request_id);

            -- Canonical content of a row: a JSON array (no ambiguity between fields) with the
            -- instant in UTC and microseconds, independent of the session time zone. jsonb
            -- normalises key order, so `changes` serialises the same way every time.
            CREATE OR REPLACE FUNCTION activity_log_payload(a activity_log) RETURNS text LANGUAGE sql IMMUTABLE AS $$
                SELECT jsonb_build_array(
                    a.id, a.seq, to_char(a.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                    a.category, a.channel, a.actor_id, a.actor_name, a.actor_role, a.action,
                    a.subject_type, a.subject_id, a.summary, a.changes, a.request_id, host(a.ip),
                    a.user_agent, a.method, a.route, a.legacy_id
                )::text
            $$;

            CREATE OR REPLACE FUNCTION activity_log_hash(previous char(64), a activity_log) RETURNS char(64) LANGUAGE sql IMMUTABLE AS $$
                SELECT encode(sha256(convert_to(coalesce(previous, '') || activity_log_payload(a), 'UTF8')), 'hex')
            $$;

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

            CREATE TRIGGER activity_log_chain BEFORE INSERT ON activity_log
                FOR EACH ROW EXECUTE FUNCTION activity_log_chain();

            CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER activity_log_no_delete BEFORE DELETE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER activity_log_no_truncate BEFORE TRUNCATE ON activity_log
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS activity_log;
            DROP FUNCTION IF EXISTS activity_log_chain();
            DROP FUNCTION IF EXISTS activity_log_hash(char, activity_log);
            DROP FUNCTION IF EXISTS activity_log_payload(activity_log);
        SQL);
    }
};
