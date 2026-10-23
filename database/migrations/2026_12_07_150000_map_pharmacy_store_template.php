<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Care Pharmacy store template was removed (owner 2026-10-24: two looks for now, Fresh Market and Butcher's
 * Block). Any store saved with it gets Fresh Market. Data only and safe to run twice; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('online_stores')->where('template', 'pharmacy')->update(['template' => 'freshMarket']);
    }

    public function down(): void
    {
        // The old value is not kept: Fresh Market is a valid look for every store.
    }
};
