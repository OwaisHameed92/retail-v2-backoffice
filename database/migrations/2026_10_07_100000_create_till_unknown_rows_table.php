<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * till_unknown_rows: rows of an entity this portal does not know yet (a newer till), kept raw so nothing is lost
     * (contract v1.4.1 §18.8, §21.1). One row per (company, entity, row id), the highest version kept; when the
     * entity gets its own table, its rows can be replayed from here.
     */
    public function up(): void
    {
        Schema::create('till_unknown_rows', function (Blueprint $table) {
            $table->id();
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->string('register_id', 26)->nullable();
            $table->string('entity', 64);
            $table->string('entity_id', 26);
            $table->char('op', 1);
            $table->unsignedBigInteger('version');
            $table->unsignedBigInteger('seq');
            $table->dateTime('at');
            $table->longText('payload')->nullable();
            $table->dateTime('received_at');

            $table->unique(['company_id', 'entity', 'entity_id'], 'till_unknown_rows_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_unknown_rows');
    }
};
