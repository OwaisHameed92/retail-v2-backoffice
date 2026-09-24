<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop of a tenant. Mirrors the till's Branch entity; the ULID is made here and sent to the till.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // 2–5 upper-case letters, used in receipt numbers (LDS-01-000482). Unique per company, never reused
            // (the unique index includes soft-deleted rows on purpose).
            $table->string('code', 5);
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('nation', 20)->default('england');
            $table->text('licensed_hours_json')->nullable();
            $table->boolean('is_drs_return_point')->default(false);
            $table->decimal('area_m2', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
