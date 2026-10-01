<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting exports (gap #8): per business and target package (xero, quickbooks, sage50, sageAccounting), our
 * account code → their account code (`kind` account) and our VAT code → their sales / purchases tax codes (`kind`
 * vat). A code with no row uses the package's default mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_export_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('target', 20);
            $table->string('kind', 10);
            $table->string('our_code', 20);
            $table->string('their_code', 100);
            $table->string('their_purchase_code', 100)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['company_id', 'target', 'kind', 'our_code'], 'accounting_export_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_mappings');
    }
};
