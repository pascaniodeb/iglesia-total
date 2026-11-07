<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE church_settings (
                id smallint PRIMARY KEY DEFAULT 1,
                name varchar(255) NOT NULL,
                pastor varchar(255) NOT NULL,
                code varchar(100) NOT NULL,
                rif varchar(100) NOT NULL,
                denomination varchar(255) NOT NULL,
                headquarters varchar(255) NOT NULL,
                registration_note text NOT NULL,
                denomination_rif varchar(100) NOT NULL,
                logo_light_path varchar(255) NULL,
                logo_light_mime varchar(100) NULL,
                logo_light_size_bytes integer NULL,
                logo_light_sha256 char(64) NULL,
                logo_dark_path varchar(255) NULL,
                logo_dark_mime varchar(100) NULL,
                logo_dark_size_bytes integer NULL,
                logo_dark_sha256 char(64) NULL,
                favicon_path varchar(255) NULL,
                favicon_mime varchar(100) NULL,
                favicon_size_bytes integer NULL,
                favicon_sha256 char(64) NULL,
                updated_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT church_settings_singleton CHECK (id = 1),
                CONSTRAINT church_settings_logo_light_complete CHECK (
                    (logo_light_path IS NULL AND logo_light_mime IS NULL AND logo_light_size_bytes IS NULL AND logo_light_sha256 IS NULL)
                    OR (logo_light_path IS NOT NULL AND logo_light_mime IS NOT NULL AND logo_light_size_bytes > 0 AND logo_light_sha256 ~ '^[0-9a-f]{64}$')
                ),
                CONSTRAINT church_settings_logo_dark_complete CHECK (
                    (logo_dark_path IS NULL AND logo_dark_mime IS NULL AND logo_dark_size_bytes IS NULL AND logo_dark_sha256 IS NULL)
                    OR (logo_dark_path IS NOT NULL AND logo_dark_mime IS NOT NULL AND logo_dark_size_bytes > 0 AND logo_dark_sha256 ~ '^[0-9a-f]{64}$')
                ),
                CONSTRAINT church_settings_favicon_complete CHECK (
                    (favicon_path IS NULL AND favicon_mime IS NULL AND favicon_size_bytes IS NULL AND favicon_sha256 IS NULL)
                    OR (favicon_path IS NOT NULL AND favicon_mime IS NOT NULL AND favicon_size_bytes > 0 AND favicon_sha256 ~ '^[0-9a-f]{64}$')
                )
            );

            CREATE INDEX church_settings_updated_by_index ON church_settings (updated_by);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS church_settings');
    }
};
