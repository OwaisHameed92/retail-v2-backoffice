<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner onboarding API v1 (docs/partner-api.md, owner 2026-10-12), additive only: four new tables, nothing else
 * changes. A partner (Zivaro) sends signed deals as tenant requests; an admin approves them into a business through
 * the normal onboarding; the partner hears back through signed webhooks (an outbox).
 *
 * - `partners`: name, webhook URL, webhook signing secret (encrypted at rest), active.
 * - `partner_keys`: API keys (HMAC hash + last 4 only; the plain key is shown once), revocable.
 * - `partner_tenant_requests`: one per partner `external_id`, ever; status, the partner's body, the business made.
 * - `partner_webhook_events`: the outbox (exact JSON body, attempts, next attempt, delivered / given up).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_secret_last4', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partner_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->string('key_hash', 64)->unique();
            $table->string('key_last4', 4);
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_tenant_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('status', 20);
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('business_name', 160);
            $table->string('seller_name', 120);
            $table->string('seller_email', 255);
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamp('approved_at', 3)->nullable();
            $table->foreignUlid('approved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('rejected_at', 3)->nullable();
            $table->foreignUlid('rejected_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('cancelled_at', 3)->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->boolean('partner_cancelled_after_approval')->default(false);
            $table->timestamp('partner_cancelled_at', 3)->nullable();
            // Millisecond precision: `updatedAt` orders the partner's view of the request (webhooks may arrive out of order).
            $table->timestamp('created_at', 3)->nullable();
            $table->timestamp('updated_at', 3)->nullable();

            $table->unique(['partner_id', 'external_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('partner_webhook_events', function (Blueprint $table) {
            $table->string('id', 30)->primary(); // "evt_" + ULID
            $table->foreignUlid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignUlid('partner_tenant_request_id')->constrained('partner_tenant_requests')->cascadeOnDelete();
            $table->string('type', 40);
            // One event per request and type (a rejection is per rejection: a rejected request can be sent again).
            $table->string('dedupe_key', 120)->unique();
            $table->longText('payload'); // the exact JSON body sent (signed as is on every attempt)
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['partner_tenant_request_id', 'type']);
            $table->index(['delivered_at', 'failed_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_events');
        Schema::dropIfExists('partner_tenant_requests');
        Schema::dropIfExists('partner_keys');
        Schema::dropIfExists('partners');
    }
};
