<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.8: invoices and their lines. Money is decimal(12,2) pounds; quantities decimal(14,4).
     * A draft has no number; the number (INV-000001) is taken when it is issued. Issued invoices never change
     * except for status, payments, credits and the sent/overdue/void stamps.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();

            $table->string('number', 20)->nullable()->unique();
            $table->unsignedBigInteger('sequence')->nullable()->unique();
            $table->string('status', 20)->index();
            $table->string('cycle', 16);

            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable()->index();

            $table->char('currency', 3)->default('GBP');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('seller_vat_number', 40)->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_credited', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);

            // Snapshot of who it is billed to, taken when issued.
            $table->string('bill_to_name', 191)->nullable();
            $table->text('bill_to_address')->nullable();
            $table->json('bill_to_emails')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('prorated')->default(false);
            $table->boolean('auto_generated')->default(false);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->timestamp('suspension_triggered_at')->nullable();
            $table->timestamp('licences_renewed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignUlid('issued_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignUlid('voided_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->ulid('replaces_invoice_id')->nullable()->index();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period_start']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('licence_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('register_id')->nullable();
            $table->ulid('plan_id')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description', 500);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('net', 12, 2);
            $table->decimal('vat', 12, 2);
            $table->decimal('gross', 12, 2);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
            $table->index('licence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
