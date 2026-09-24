<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A till of a branch. Mirrors the till's Register entity. Sale/refund counters are till-owned and not kept here.
     */
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            // Two digits "01".."99", unique per branch, never reused (index includes soft-deleted rows).
            $table->string('code', 2);
            $table->string('name');
            $table->boolean('is_main_till')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
            $table->index(['company_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
