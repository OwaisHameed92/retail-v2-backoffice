<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.8: billing settings of each company (one row, created on first use) and the gap-free document
     * number counters (invoice, credit note, payment receipt).
     */
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained()->cascadeOnDelete();

            // Null = the company's legal name (or name) and address.
            $table->string('billing_name', 191)->nullable();
            $table->text('billing_address')->nullable();
            // Null or empty = the company's active owners.
            $table->json('billing_emails')->nullable();

            $table->string('cycle', 16)->default('monthly');
            $table->unsignedSmallInteger('payment_terms_days')->default(7);
            $table->boolean('vat_applies')->default(true);

            // Set when billing:run suspended the company (so only billing lifts its own suspension).
            $table->timestamp('billing_suspended_at')->nullable();
            $table->ulid('suspension_invoice_id')->nullable();

            // Trial emails: sent once per trial end date.
            $table->timestamp('trial_reminder_for')->nullable();
            $table->timestamp('trial_reminder_sent_at')->nullable();
            $table->timestamp('trial_ended_for')->nullable();
            $table->timestamp('trial_ended_sent_at')->nullable();

            $table->timestamps();
        });

        // One row per counter. Numbers are taken with an UPDATE inside the issuing transaction, which locks the
        // row until commit: no duplicates under concurrency, and a rolled-back issue gives its number back.
        Schema::create('billing_sequences', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        DB::table('billing_sequences')->insert([
            ['name' => 'invoice', 'last_value' => 0],
            ['name' => 'credit_note', 'last_value' => 0],
            ['name' => 'payment', 'last_value' => 0],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_sequences');
        Schema::dropIfExists('billing_accounts');
    }
};
