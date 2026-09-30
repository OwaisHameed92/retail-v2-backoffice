<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.1 (stock screens). The movements list pages newest first by (at, id): one shop uses the generated
 * (company_id, branch_id, product_id, at) index only with a product, so add (company_id, branch_id, at) and, for every
 * shop, (company_id, at); a product's history across every shop needs (company_id, product_id, at). Near-expiry
 * batches are found by (company_id, expiry_date); stock takes are listed by (company_id, branch_id, started_at).
 * Hand-written, additive, not in till-schema.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'at'], 'stock_movements_company_branch_at_index');
            $table->index(['company_id', 'at'], 'stock_movements_company_at_index');
            $table->index(['company_id', 'product_id', 'at'], 'stock_movements_company_product_at_index');
        });
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->index(['company_id', 'expiry_date'], 'stock_layers_company_expiry_index');
        });
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'started_at'], 'stock_takes_company_branch_started_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->dropIndex('stock_takes_company_branch_started_index');
        });
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropIndex('stock_layers_company_expiry_index');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_company_product_at_index');
            $table->dropIndex('stock_movements_company_at_index');
            $table->dropIndex('stock_movements_company_branch_at_index');
        });
    }
};
