<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.6 (sales and receipts, millions of sales per business): the list pages every shop's sales newest first by
 * (trading_day, id) without counting them, so a business-wide list needs (company_id, trading_day, id); one shop uses
 * 3.1's (company_id, branch_id, trading_day) index (InnoDB adds the id). A sale's refunds and exchanges are found by
 * (company_id, original_sale_id). Hand-written, additive, not in till-schema.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['company_id', 'trading_day', 'id'], 'sales_company_trading_day_id_index');
            $table->index(['company_id', 'original_sale_id'], 'sales_company_original_sale_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_original_sale_index');
            $table->dropIndex('sales_company_trading_day_id_index');
        });
    }
};
