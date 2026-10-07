<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11 (owner 2026-10-07): tenant emails only when the admin wants.
 *
 * - `email_settings`: one row per email category the admin changed ("Send automatically" on or off). No row = on,
 *   so every email goes as before until the owner switches a category off.
 * - `held_emails`: an email that was not sent because its category is off, kept (the message encrypted with the app
 *   key, as on the queue) until an admin sends or discards it. The payload is cleared once it is acted on. Not
 *   tenant-scoped (an admin screen), like `email_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table) {
            $table->string('category', 32)->primary();
            $table->boolean('send_automatically')->default(true);
            $table->string('updated_by_admin_id', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('held_emails', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email_log_id', 26)->nullable()->index();
            // No FK on purpose (as email_logs): never blocks a company delete.
            $table->string('company_id', 26)->nullable()->index();
            $table->string('category', 32);
            $table->string('template', 64);
            $table->string('to', 320);
            $table->string('subject', 255)->nullable();
            $table->longText('payload')->nullable();
            $table->string('status', 16)->default('held');
            $table->string('actioned_by_admin_id', 26)->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_emails');
        Schema::dropIfExists('email_settings');
    }
};
