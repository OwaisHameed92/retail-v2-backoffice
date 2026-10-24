<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online store OS-10: each store's own email settings (the portal's Emails tab). Additive only, one row per store.
 *
 * - `sender_name` / `reply_to`: the customer emails' From name and Reply-To (blank = the business name / the order
 *   email).
 * - `smtp_*`: "Send from our own email server". `smtp_password` is encrypted by the model (encrypted cast, never
 *   plain in the database); `smtp_password_last4` is what the portal shows ("saved, ending ••1234").
 * - `smtp_verified_at`: set by a successful test email, cleared when the server settings change. Only a verified
 *   server is used for real customer emails.
 * - `last_error` / `last_error_at`: the last failure in plain words (no secrets), shown on the Emails tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_mail_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_id')->constrained('online_stores')->cascadeOnDelete();
            $table->string('sender_name', 70)->nullable();
            $table->string('reply_to', 255)->nullable();
            $table->boolean('smtp_enabled')->default(false);
            $table->string('smtp_host', 253)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 8)->nullable();
            $table->string('smtp_username', 255)->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_password_last4', 4)->nullable();
            $table->string('smtp_from_address', 255)->nullable();
            $table->dateTime('smtp_verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->dateTime('last_test_at')->nullable();
            $table->timestamps();

            $table->unique('online_store_id', 'online_store_mail_settings_store_unique');
            $table->index('company_id', 'online_store_mail_settings_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_mail_settings');
    }
};
