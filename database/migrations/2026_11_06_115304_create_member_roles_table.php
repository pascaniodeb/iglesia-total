<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->date('since')->nullable();
            $table->date('until')->nullable();
            $table->timestampsTz();

            $table->index('member_id');
            // "Who are the deacons?"
            $table->index(['role', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_roles');
    }
};
