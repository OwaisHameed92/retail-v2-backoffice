<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Till 0.1.85 (PORTAL-CHANGES-pharmacy items 9 and 14): `rpt_sales_daily` keeps a pharmacy's NHS prescription charges
 * (`RX-CHARGE`) and prepayment certificates (`RX-PPC-*`) apart: not sales, not in any VAT figure, still in takings.
 * Additive, default 0: no other shop sends an `RX-` line, so no stored figure changes and no rebuild is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpt_sales_daily', function (Blueprint $table) {
            $table->decimal('nhs_charges', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('rpt_sales_daily', function (Blueprint $table) {
            $table->dropColumn('nhs_charges');
        });
    }
};
