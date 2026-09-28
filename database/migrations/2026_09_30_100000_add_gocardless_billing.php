<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.12: GoCardless Direct Debit. A setup fee per plan (with a per-company override), how each company
     * pays (upfront cash or Direct Debit), its GoCardless mandate and subscription, every GoCardless payment
     * mapped to one of our invoices, and every webhook event (idempotent by GoCardless event id).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Net, pounds. Charged once per company.
            $table->decimal('setup_fee', 12, 2)->default(0)->after('price_per_till_yearly');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('upfrontCash')->after('vat_applies');

            // Setup fee: null override = the plan's; paid manually (cash/bank) or by Direct Debit, in 1-12 parts.
            $table->decimal('setup_fee_override', 12, 2)->nullable();
            $table->string('setup_fee_method', 20)->default('directDebit');
            $table->unsignedTinyInteger('setup_fee_instalments')->default(1);
            $table->timestamp('setup_fee_invoiced_at')->nullable();

            // GoCardless ids and state (ids are not secret).
            $table->string('gc_customer_id', 40)->nullable();
            $table->string('gc_billing_request_id', 40)->nullable()->index();
            $table->text('gc_setup_url')->nullable();
            $table->timestamp('gc_setup_url_expires_at')->nullable();
            $table->timestamp('gc_setup_sent_at')->nullable();
            $table->string('gc_mandate_id', 40)->nullable()->index();
            $table->string('gc_mandate_status', 30)->nullable();
            $table->timestamp('gc_mandate_active_at')->nullable();
            $table->timestamp('gc_mandate_lost_at')->nullable();
            $table->timestamp('mandate_overdue_at')->nullable();
            $table->timestamp('mandate_grace_suspended_for')->nullable();
            $table->string('gc_subscription_id', 40)->nullable()->index();
            $table->string('gc_subscription_status', 30)->nullable();
            $table->decimal('gc_subscription_amount', 12, 2)->nullable();
            $table->string('gc_subscription_cycle', 16)->nullable();
            $table->date('gc_next_charge_date')->nullable();
            $table->timestamp('gc_reconciled_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // subscription = tills for a period; setupFee = the one-off setup fee (or one instalment of it).
            $table->string('kind', 20)->default('subscription')->after('cycle')->index();
        });

        Schema::table('payments', function (Blueprint $table) {
            // A Direct Debit charged back (or failed after we recorded it): allocations released, nothing credited.
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
        });

        Schema::create('gocardless_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();
            $table->string('gc_payment_id', 40)->unique();
            $table->string('gc_subscription_id', 40)->nullable()->index();
            $table->string('gc_mandate_id', 40)->nullable();
            $table->string('kind', 20);
            $table->foreignUlid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('charge_date')->nullable();
            $table->string('status', 30)->index();
            $table->string('description', 191)->nullable();
            $table->unsignedTinyInteger('instalment')->nullable();
            $table->unsignedTinyInteger('instalments')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('failure_notified_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'charge_date']);
        });

        Schema::create('gocardless_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('gc_event_id', 40)->unique();
            $table->foreignUlid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type', 40);
            $table->string('action', 60);
            $table->json('links')->nullable();
            $table->json('details')->nullable();
            $table->json('payload');
            // pending → processed | ignored | failed (replayable).
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamp('gc_created_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gocardless_events');
        Schema::dropIfExists('gocardless_payments');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropIndex(['gc_billing_request_id']);
            $table->dropIndex(['gc_mandate_id']);
            $table->dropIndex(['gc_subscription_id']);
            $table->dropColumn([
                'billing_mode', 'setup_fee_override', 'setup_fee_method', 'setup_fee_instalments', 'setup_fee_invoiced_at',
                'gc_customer_id', 'gc_billing_request_id', 'gc_setup_url', 'gc_setup_url_expires_at', 'gc_setup_sent_at',
                'gc_mandate_id', 'gc_mandate_status', 'gc_mandate_active_at', 'gc_mandate_lost_at', 'mandate_overdue_at',
                'mandate_grace_suspended_for', 'gc_subscription_id', 'gc_subscription_status', 'gc_subscription_amount',
                'gc_subscription_cycle', 'gc_next_charge_date', 'gc_reconciled_at',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('setup_fee');
        });
    }
};
