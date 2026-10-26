<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OS-13 (owner 2026-10-26): an "Online store only" offer for a business with no till yet. Additive, portal only:
 *
 * - `plans.online_store_only`: a recurring plan with no tills whose whole monthly fee is the online store fee
 *   (`online_store_monthly_price`, once per business). Default false: every existing plan is unchanged.
 * - `online_stores.sells_without_tills`: the business started on such a plan, so until it has a live till its store
 *   sells the portal's products with the shop's own stock (also after Change plan to a plan with tills, until the
 *   first till licence is issued). Default false: existing stores are unchanged.
 * - `online_store_products.out_of_stock` / `stock_count`: the shop's own "In stock / Out of stock" and optional stock
 *   count, used only while the business has no live till licence. Never written to the till tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('online_store_only')->default(false);
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->boolean('sells_without_tills')->default(false);
        });

        Schema::table('online_store_products', function (Blueprint $table) {
            $table->boolean('out_of_stock')->default(false);
            $table->decimal('stock_count', 14, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_store_products', function (Blueprint $table) {
            $table->dropColumn(['out_of_stock', 'stock_count']);
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn('sells_without_tills');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('online_store_only');
        });
    }
};
