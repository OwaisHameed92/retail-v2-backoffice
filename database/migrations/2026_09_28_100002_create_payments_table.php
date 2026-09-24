<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.8: payments recorded by staff (cash, bank transfer, other; an online gateway later), their
     * allocations to invoices, and credit notes.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();

            $table->string('number', 20)->unique();
            $table->unsignedBigInteger('sequence')->unique();
            $table->string('method', 20)->index();
            $table->decimal('amount', 12, 2);
            // Part of the amount not allocated to any invoice: the company's credit.
            $table->decimal('unallocated', 12, 2)->default(0);
            $table->timestamp('received_at')->index();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('received_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();

            // For the online gateway (later): which provider and its payment id.
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_reference', 191)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'received_at']);
            $table->index(['gateway', 'gateway_reference']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            // Set when the invoice is voided and the money goes back to the company's credit.
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'released_at']);
            $table->index(['payment_id', 'released_at']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();

            $table->string('number', 20)->unique();
            $table->unsignedBigInteger('sequence')->unique();
            $table->string('reason', 500);
            $table->decimal('net', 12, 2);
            $table->decimal('vat', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamp('issued_at');
            $table->foreignUlid('issued_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
