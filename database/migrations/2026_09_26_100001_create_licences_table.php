<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.3: one licence per till (register). The key itself is never stored: only an HMAC-SHA256 of it
     * (keyed with APP_KEY) and its last 4 characters.
     */
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('register_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();

            // "One live licence per till": equals register_id while the licence is not revoked (or deleted),
            // null afterwards. Unique indexes allow many NULLs on SQLite and MySQL alike.
            $table->ulid('live_register_id')->nullable()->unique();

            $table->char('key_hash', 64)->unique();
            $table->char('key_last4', 4)->index();

            $table->string('status', 16)->index();
            $table->json('features');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(0);
            // Derived on save (expires_at ?? trial_ends_at, then + grace_days) so lists can filter on dates in SQL.
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('grace_ends_at')->nullable()->index();

            $table->string('device_id', 191)->nullable()->index();
            $table->string('device_name', 191)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->string('last_app_version', 50)->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['register_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
