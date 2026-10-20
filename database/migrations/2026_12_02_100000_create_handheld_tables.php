<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handheld (PDA) API, phase 1 (docs/pda-api.md, DECISIONS "Handheld (PDA) API"), additive only: two new tables,
 * nothing else changes.
 *
 * - `handheld_users`: a tenant-owned login for the Android PDA app, tied to one shop. Email unique per business,
 *   bcrypt password, active flag, may change prices (default no). Never sent to a till.
 * - `handheld_tokens`: the app's bearer tokens (SHA-256 hash + last 4 only; the plain token is in the login reply
 *   only), last use and revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handheld_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('can_change_prices')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'email'], 'handheld_users_company_email_unique');
            $table->index('email', 'handheld_users_email_index');
        });

        Schema::create('handheld_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('handheld_user_id')->constrained('handheld_users')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('token_last4', 4);
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'handheld_tokens_token_hash_unique');
            $table->index(['handheld_user_id', 'revoked_at'], 'handheld_tokens_user_revoked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handheld_tokens');
        Schema::dropIfExists('handheld_users');
    }
};
