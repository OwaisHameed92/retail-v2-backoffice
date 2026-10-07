<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11 (owner 2026-10-07), additive only:
 *
 * - `plans.setup_fee_mode`: `perBusiness` (the setup fee is charged once per business, as before: every existing plan
 *   gets it) or `perTill` (charged for each till, also for tills added later).
 * - `billing_accounts.setup_fee_covered_tills`: how many tills a setup fee already accounts for (charged, paid or
 *   waived). Null = not tracked yet (the tills a business already has count as covered); filled by
 *   `billing:backfill-setup-fee-coverage` and kept up to date when tills are added.
 * - `billing_accounts.till_setup_fee_override`: the business's own setup fee per added till (net); null = the plan's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('setup_fee_mode', 16)->default('perBusiness');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->unsignedInteger('setup_fee_covered_tills')->nullable();
            $table->decimal('till_setup_fee_override', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn(['setup_fee_covered_tills', 'till_setup_fee_override']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('setup_fee_mode');
        });
    }
};
