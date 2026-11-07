<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The support of a movement: the receipt or invoice number and/or a photo or PDF of it, in
     * private storage, with its SHA-256 so a later change to the file is detected on every
     * download. What turns "the numbers add up" into "the numbers match the paper".
     *
     * One row per entry, append-only. A correction carries the original's support to the
     * corrected entry (a new row pointing to the same file and fingerprint).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE journal_entry_supports (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                journal_entry_id uuid NOT NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
                receipt_number varchar(60) NULL,
                file_path varchar(255) NULL,
                file_name varchar(255) NULL,
                mime_type varchar(100) NULL,
                size_bytes integer NULL,
                sha256 char(64) NULL,
                uploaded_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT journal_entry_supports_entry_unique UNIQUE (journal_entry_id),
                CONSTRAINT journal_entry_supports_something CHECK (receipt_number IS NOT NULL OR file_path IS NOT NULL),
                CONSTRAINT journal_entry_supports_receipt_not_blank CHECK (receipt_number IS NULL OR btrim(receipt_number) <> ''),
                CONSTRAINT journal_entry_supports_file_complete CHECK (
                    (file_path IS NULL AND file_name IS NULL AND mime_type IS NULL AND size_bytes IS NULL AND sha256 IS NULL)
                    OR (file_path IS NOT NULL AND file_name IS NOT NULL AND mime_type IS NOT NULL AND size_bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$')
                )
            );

            CREATE INDEX journal_entry_supports_uploaded_by_index ON journal_entry_supports (uploaded_by);

            CREATE TRIGGER journal_entry_supports_no_update BEFORE UPDATE ON journal_entry_supports
                FOR EACH ROW EXECUTE FUNCTION forbid_update();
            CREATE TRIGGER journal_entry_supports_no_delete BEFORE DELETE ON journal_entry_supports
                FOR EACH ROW EXECUTE FUNCTION forbid_physical_delete();
            CREATE TRIGGER journal_entry_supports_no_truncate BEFORE TRUNCATE ON journal_entry_supports
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_physical_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS journal_entry_supports');
    }
};
