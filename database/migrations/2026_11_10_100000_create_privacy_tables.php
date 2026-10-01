<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7.7 (privacy, GDPR): customer data requests (export / erasure) and each business's data retention setting.
 * A request row never holds the customer's personal details: only the customer's id and what was done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('customer_id', 64)->nullable();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('source', 20)->default('owner');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->json('till_steps')->nullable();
            $table->dateTime('till_done_at')->nullable();
            $table->unsignedBigInteger('till_done_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('company_privacy_settings', function (Blueprint $table) {
            $table->string('company_id', 26)->primary();
            $table->unsignedSmallInteger('retention_months')->nullable();
            $table->boolean('auto_anonymise')->default(false);
            $table->dateTime('last_checked_at')->nullable();
            $table->unsignedInteger('due_count')->default(0);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_privacy_settings');
        Schema::dropIfExists('data_requests');
    }
};
