<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salon W3b (docs/salon.md "Staff calendar feed"), additive only: one new table, nothing else changes.
 *
 * `salon_calendar_feeds`: one row per stylist and shop (`user_id` = the till user id of the stylist). The feed's
 * token is random, shown once, and stored as a SHA-256 hash + its last 4 characters only. Turning a feed off clears
 * the hash (the old address 404s for good); turning it on again issues a new token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_calendar_feeds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('user_id', 64);
            $table->string('token_hash', 64)->nullable();
            $table->string('token_last4', 4)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'user_id'], 'salon_calendar_feeds_stylist_unique');
            $table->unique('token_hash', 'salon_calendar_feeds_token_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_calendar_feeds');
    }
};
