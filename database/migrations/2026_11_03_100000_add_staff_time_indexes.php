<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.6 (staff time): indexes for reading clock events by time (every shop or one shop) and the rota by day.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clock_events', function (Blueprint $table) {
            $table->index(['company_id', 'at'], 'clock_events_company_at_index');
            $table->index(['company_id', 'branch_id', 'at'], 'clock_events_company_branch_at_index');
        });

        Schema::table('rota_shifts', function (Blueprint $table) {
            $table->index(['company_id', 'shift_date'], 'rota_shifts_company_shift_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('clock_events', function (Blueprint $table) {
            $table->dropIndex('clock_events_company_at_index');
            $table->dropIndex('clock_events_company_branch_at_index');
        });

        Schema::table('rota_shifts', function (Blueprint $table) {
            $table->dropIndex('rota_shifts_company_shift_date_index');
        });
    }
};
