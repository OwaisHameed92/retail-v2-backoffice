<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-21: the handheld (PDA) app comes with monthly / yearly plans. `plans.includes_handheld` is a
 * portal-only plan setting (never in the till's Feature list or the licence token). Additive only.
 *
 * Backfill: true for every plan (archived ones too) with a monthly or yearly price above 0, false otherwise. On the
 * UK that makes Standard, "Setup + monthly" and "Monthly only" true and "Setup only" false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('includes_handheld')->default(false);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('includes_handheld');
        });
    }

    private function backfill(): void
    {
        DB::table('plans')->update(['includes_handheld' => false]);
        DB::table('plans')
            ->where(fn ($query) => $query->where('price_monthly', '>', 0)->orWhere('price_yearly', '>', 0))
            ->update(['includes_handheld' => true]);
    }
};
