<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            // UUIDv7: time-ordered, so inserts stay append-only in the index and
            // keyset pagination by id is stable. The database default covers rows
            // not created through Eloquent.
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->unsignedInteger('legacy_id')->nullable()->unique();

            // Everything except full_name is nullable: the migrated registry is
            // mostly bare names and a NOT NULL column would block corrections.
            $table->string('full_name');
            $table->enum('document_type', ['V', 'E', 'pasaporte', 'sin_documento'])->nullable();
            $table->string('document_id')->nullable();
            $table->enum('sex', ['femenino', 'masculino'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place_city')->nullable();
            $table->string('birth_place_state')->nullable();

            $table->string('phone')->nullable();
            $table->string('landline_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address_state')->nullable();
            $table->string('address_municipality')->nullable();
            $table->string('address_parish')->nullable();
            $table->text('address')->nullable();

            $table->enum('education_level', ['ninguno', 'primaria', 'bachillerato', 'tsu', 'universitario', 'postgrado'])->nullable();
            $table->string('profession')->nullable();
            $table->string('occupation')->nullable();
            $table->enum('marital_status', ['soltero', 'casado', 'union_estable_de_hecho', 'viudo', 'divorciado'])->nullable();

            // The spouse is another member (families are grouped through it).
            // UNIQUE: marriage is exclusive. Symmetry A->B / B->A is kept by the app.
            $table->uuid('spouse_id')->nullable()->unique();

            $table->date('baptism_date')->nullable();
            $table->string('baptism_place')->nullable();
            $table->string('baptism_pastor')->nullable();
            $table->date('holy_spirit_date')->nullable();
            $table->date('joined_date')->nullable();
            $table->enum('status', ['activo', 'trasladado', 'inactivo', 'fallecido'])->nullable();
            $table->text('pastor_notes')->nullable();
            $table->string('photo_path')->nullable();

            // Lowercase, accent-free copy of full_name maintained by the database.
            // Used for search (trigram index) and as the stable sort key.
            $table->string('search_name')->storedAs('lower(immutable_unaccent(full_name))');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Self-referencing FK: it needs the primary key, which Laravel adds after the table is created.
        Schema::table('members', function (Blueprint $table) {
            $table->foreign('spouse_id')->references('id')->on('members')->nullOnDelete();
        });

        DB::statement('ALTER TABLE members ADD CONSTRAINT members_spouse_not_self CHECK (spouse_id IS NULL OR spouse_id <> id)');

        // Unlimited NULLs, unique once a document is provided.
        DB::statement('CREATE UNIQUE INDEX members_document_id_unique ON members (document_id) WHERE document_id IS NOT NULL');

        // Name search: search_name LIKE '%term%'.
        DB::statement('CREATE INDEX members_search_name_trgm_idx ON members USING gin (search_name gin_trgm_ops)');

        // Default list (not deleted), ordered by name with id as the keyset tiebreaker,
        // and the same list filtered by status.
        DB::statement('CREATE INDEX members_active_name_idx ON members (search_name, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX members_status_name_idx ON members (status, search_name, id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
