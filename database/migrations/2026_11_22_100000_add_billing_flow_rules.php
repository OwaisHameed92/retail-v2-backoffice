<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner billing rules (2026-10-05), additive only:
 *
 * - `plans.billing_type`: setupOnly / setupAndRecurring / recurringOnly, filled from the prices of existing plans.
 * - `invoices.reopened_at`: when a Direct Debit failure or chargeback made a paid invoice owed again (the
 *   suspension grace counts from then, not from the old due date).
 * - `billing_accounts.mandate_reminder_for`: the Direct Debit deadline the reminder email was sent for (once each).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('billing_type', 30)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable();
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->timestamp('mandate_reminder_for')->nullable();
        });

        DB::table('plans')->where('setup_fee', '<=', 0)->update(['billing_type' => 'recurringOnly']);
        DB::table('plans')->whereNull('billing_type')->where('price_monthly', '<=', 0)->where('price_yearly', '<=', 0)->update(['billing_type' => 'setupOnly']);
        DB::table('plans')->whereNull('billing_type')->update(['billing_type' => 'setupAndRecurring']);
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn('mandate_reminder_for');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('reopened_at');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};
