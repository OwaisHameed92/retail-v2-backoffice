<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.8 (reports): shifts are read by close time and Z reports by period end across every shop of a business
 * (the till's indexes lead with the shop). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['company_id', 'closed_at'], 'shifts_company_id_closed_at_index');
            $table->index(['company_id', 'status'], 'shifts_company_id_status_index');
        });

        Schema::table('z_reports', function (Blueprint $table) {
            $table->index(['company_id', 'period_end'], 'z_reports_company_id_period_end_index');
            $table->index(['shift_id'], 'z_reports_shift_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('z_reports', function (Blueprint $table) {
            $table->dropIndex('z_reports_shift_id_index');
            $table->dropIndex('z_reports_company_id_period_end_index');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_company_id_status_index');
            $table->dropIndex('shifts_company_id_closed_at_index');
        });
    }
};
