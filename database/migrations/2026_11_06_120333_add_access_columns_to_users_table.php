<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->unique();

            // Least privilege by default. Never assignable from a request.
            $table->enum('role', ['pastor', 'secretaria', 'tesoreria', 'contraloria', 'miembro'])->default('miembro');
            $table->boolean('is_active')->default(true);

            // A user is a member: the account belongs to a person of the registry.
            // Nullable only while accounts that predate the link exist; unique so a
            // member has at most one account. RESTRICT: a member with an account is
            // never hard-deleted by accident.
            $table->foreignUuid('member_id')->nullable()->unique()->constrained('members')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn(['legacy_id', 'role', 'is_active']);
        });
    }
};
