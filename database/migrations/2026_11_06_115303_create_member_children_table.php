<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_children', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->timestampsTz();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_children');
    }
};
