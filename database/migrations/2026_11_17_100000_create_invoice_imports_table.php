<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice import (module 6.5), portal-only: never synced. `SupplierInvoice` is branch-owned in ownership.json, so the
 * portal keeps its own record of an uploaded supplier invoice or delivery note: the private file (deleted after the
 * retention period), what the model read, the user's corrected draft and what confirming it created (a head-office
 * order, cost price updates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20);
            $table->string('method', 10);
            $table->string('file_name', 190)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('file_mime', 60)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->dateTime('file_purged_at')->nullable();
            $table->string('supplier_id', 26)->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('gross_total', 12, 2)->nullable();
            $table->json('extracted')->nullable();
            $table->json('draft')->nullable();
            $table->string('error', 300)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('purchase_order_id', 26)->nullable();
            $table->json('result')->nullable();
            $table->dateTime('extracted_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['company_id', 'branch_id', 'created_at'], 'invoice_imports_list_index');
            $table->index(['company_id', 'supplier_id', 'invoice_number'], 'invoice_imports_duplicate_index');
            $table->index(['created_at'], 'invoice_imports_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_imports');
    }
};
