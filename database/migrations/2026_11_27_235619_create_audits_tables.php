<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The comptroller's audits. An audit run is the ACT (who ran it, when, what the findings looked
     * like at that moment, the notes): the checks themselves are recomputed live, never cached. An
     * acknowledgement says "this finding, with this data, was reviewed, and here is why": it is tied
     * to a fingerprint (SHA-256 of the check, the subject and the detail), so if the data changes the
     * fingerprint changes and the finding comes back as pending without anyone remembering to revoke.
     * A revocation is another row, never an edit.
     *
     * All three tables are append-only: nothing that was recorded can be changed or erased, the
     * acknowledgement included (a finding hidden is a finding lost). The reasons cannot be blank.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audits (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                ran_at timestamptz NOT NULL DEFAULT now(),
                ran_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                status varchar(10) NOT NULL,
                findings_count integer NOT NULL DEFAULT 0,
                critical_count integer NOT NULL DEFAULT 0,
                acknowledged_count integer NOT NULL DEFAULT 0,
                snapshot jsonb NOT NULL,
                notes text NULL,
                CONSTRAINT audits_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT audits_status_check CHECK (status IN ('clean', 'findings')),
                CONSTRAINT audits_counts_check CHECK (findings_count >= 0 AND critical_count >= 0 AND critical_count <= findings_count AND acknowledged_count >= 0),
                CONSTRAINT audits_status_matches_findings CHECK ((status = 'clean') = (findings_count = 0)),
                CONSTRAINT audits_snapshot_object CHECK (jsonb_typeof(snapshot) = 'object')
            );
            CREATE INDEX audits_ran_at_idx ON audits (ran_at DESC);

            CREATE TABLE audit_acknowledgements (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                legacy_id integer NULL,
                fingerprint char(64) NOT NULL,
                check_id varchar(60) NOT NULL,
                subject text NOT NULL,
                detail text NOT NULL,
                reason text NOT NULL,
                acknowledged_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                acknowledged_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT audit_acknowledgements_legacy_id_unique UNIQUE (legacy_id),
                CONSTRAINT audit_acknowledgements_fingerprint_hex CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                CONSTRAINT audit_acknowledgements_reason_not_blank CHECK (length(btrim(reason)) > 0)
            );
            CREATE INDEX audit_acknowledgements_fingerprint_idx ON audit_acknowledgements (fingerprint);
            CREATE INDEX audit_acknowledgements_check_idx ON audit_acknowledgements (check_id);

            CREATE TABLE audit_acknowledgement_revocations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                acknowledgement_id uuid NOT NULL REFERENCES audit_acknowledgements (id) ON DELETE RESTRICT,
                reason text NOT NULL,
                revoked_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                revoked_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT audit_acknowledgement_revocations_once UNIQUE (acknowledgement_id),
                CONSTRAINT audit_acknowledgement_revocations_reason_not_blank CHECK (length(btrim(reason)) > 0)
            );
        SQL);

        foreach (['audits', 'audit_acknowledgements', 'audit_acknowledgement_revocations'] as $table) {
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
            DROP TABLE IF EXISTS audit_acknowledgement_revocations;
            DROP TABLE IF EXISTS audit_acknowledgements;
            DROP TABLE IF EXISTS audits;
        SQL);
    }
};
