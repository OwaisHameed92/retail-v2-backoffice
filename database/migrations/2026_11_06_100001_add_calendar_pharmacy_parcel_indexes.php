<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules 5.9 and 5.10: indexes for reading the tills' special days and seasonal events by date, dispensing records
 * by time for every shop, and parcels by time. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_hours_overrides', function (Blueprint $table) {
            $table->index(['company_id', 'date'], 'branch_hours_overrides_company_date_index');
        });

        Schema::table('seasonal_events', function (Blueprint $table) {
            $table->index(['company_id', 'starts_on'], 'seasonal_events_company_starts_on_index');
        });

        Schema::table('dispensing_records', function (Blueprint $table) {
            $table->index(['company_id', 'dispensed_at'], 'dispensing_records_company_dispensed_at_index');
        });

        Schema::table('parcels', function (Blueprint $table) {
            $table->index(['company_id', 'registered_at_utc'], 'parcels_company_registered_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('branch_hours_overrides', fn (Blueprint $table) => $table->dropIndex('branch_hours_overrides_company_date_index'));
        Schema::table('seasonal_events', fn (Blueprint $table) => $table->dropIndex('seasonal_events_company_starts_on_index'));
        Schema::table('dispensing_records', fn (Blueprint $table) => $table->dropIndex('dispensing_records_company_dispensed_at_index'));
        Schema::table('parcels', fn (Blueprint $table) => $table->dropIndex('parcels_company_registered_at_index'));
    }
};
