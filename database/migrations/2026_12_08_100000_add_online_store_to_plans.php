<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OS-7 (owner 2026-10-24, option B): a plan can include the online store add-on. `includes_online_store` (default
 * false: every existing plan stays without it) and `online_store_monthly_price` (pounds before VAT; null = the default
 * `online_store.default_monthly_price`). Portal only: never in the till's Feature list or the licence token. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('includes_online_store')->default(false);
            $table->decimal('online_store_monthly_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['includes_online_store', 'online_store_monthly_price']);
        });
    }
};
