<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.9: each shop's weekly opening hours, kept on the portal (the contract has no weekly-hours entity; the till
 * owns its special days as `BranchHoursOverride`). One row per shop and ISO weekday (1 = Monday … 7 = Sunday).
 * Times are the shop's local (Europe/London) wall-clock times, "HH:MM". A closing time at or before the opening time
 * means the shop closes after midnight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_opening_hours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_closed')->default(false);
            $table->string('opens_at', 5)->nullable();
            $table->string('closes_at', 5)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'weekday'], 'shop_opening_hours_branch_weekday_unique');
            $table->index(['company_id', 'branch_id'], 'shop_opening_hours_company_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_opening_hours');
    }
};
