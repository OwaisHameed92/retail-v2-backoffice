<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.13: plans price per till or per branch (the per-till columns become the plan's unit prices; existing
     * plans stay per till), a per-company pricing override, the upfront payment recorded at onboarding, the
     * Direct Debit deadline, and per-branch invoice lines.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price_per_till_monthly', 'price_monthly');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price_per_till_yearly', 'price_yearly');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('pricing_mode', 20)->default('perTill')->after('description');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            // Null = the plan's.
            $table->string('pricing_mode_override', 20)->nullable();
            $table->decimal('price_monthly_override', 12, 2)->nullable();
            $table->decimal('price_yearly_override', 12, 2)->nullable();

            // What was paid upfront when the business was onboarded (gross, pounds; 0 = nothing to pay).
            $table->decimal('upfront_amount', 12, 2)->nullable();
            $table->string('upfront_method', 20)->nullable();
            $table->timestamp('upfront_recorded_at')->nullable();

            // A Direct Debit company must have a mandate by then, or billing:run suspends it.
            $table->timestamp('mandate_deadline_at')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            // Per-branch pricing: one line per branch; paying it renews that branch's tills.
            $table->ulid('branch_id')->nullable()->after('register_id')->index();
        });

        // Direct Debit companies still without a mandate get the deadline from today.
        $days = max(0, (int) config('billing.direct_debit.mandate_deadline_days', 3));
        DB::table('billing_accounts')->where('billing_mode', 'directDebit')->whereNull('gc_mandate_id')
            ->update(['mandate_deadline_at' => now()->addDays($days)]);
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'pricing_mode_override', 'price_monthly_override', 'price_yearly_override',
                'upfront_amount', 'upfront_method', 'upfront_recorded_at', 'mandate_deadline_at',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price_monthly', 'price_per_till_monthly');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price_yearly', 'price_per_till_yearly');
        });
    }
};
