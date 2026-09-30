<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Till 0.1.15 (PORTAL-CHANGES-0.1.15 items 5, 9, 10): `rpt_sales_daily` keeps the staff-purchase discount apart from
 * manual ones, and the order deposits and charity round-ups that are no longer sales. Additive; run
 * `php artisan reports:rebuild` after deploying (a formula change, docs/reporting.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpt_sales_daily', function (Blueprint $table) {
            $table->decimal('staff_discount', 12, 2)->default(0);
            $table->decimal('order_deposits', 12, 2)->default(0);
            $table->decimal('charity', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('rpt_sales_daily', function (Blueprint $table) {
            $table->dropColumn(['staff_discount', 'order_deposits', 'charity']);
        });
    }
};
