<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Role values of the old system (feminine nouns) become the office holder's title,
     * and `asistente` is added. Existing accounts keep their office.
     */
    private const RENAMES = [
        'secretaria' => 'secretario',
        'tesoreria' => 'tesorero',
        'contraloria' => 'contralor',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');

        foreach (self::RENAMES as $old => $new) {
            DB::table('users')->where('role', $old)->update(['role' => $new]);
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('pastor', 'asistente', 'secretario', 'tesorero', 'contralor', 'miembro'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');

        foreach (self::RENAMES as $old => $new) {
            DB::table('users')->where('role', $new)->update(['role' => $old]);
        }

        // The old system had no assistant: the closest account is a plain member.
        DB::table('users')->where('role', 'asistente')->update(['role' => 'miembro']);

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('pastor', 'secretaria', 'tesoreria', 'contraloria', 'miembro'))");
    }
};
