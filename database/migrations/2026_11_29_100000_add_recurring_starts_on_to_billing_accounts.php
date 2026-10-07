<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change plan (owner 2026-10-07), additive only: `billing_accounts.recurring_starts_on`, the day a business's first
 * monthly or yearly period starts after an admin moved it from a setup-only plan to a recurring one (the first period
 * invoice is raised at the change). The Direct Debit subscription created next starts collecting from that day (or the
 * mandate's first possible day), so its first payment collects that invoice. Cleared once the subscription exists.
 * Null on every existing row: nothing changes for a business until an admin changes its plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->date('recurring_starts_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn('recurring_starts_on');
        });
    }
};
