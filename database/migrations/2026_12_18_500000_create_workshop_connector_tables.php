<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BikeBook POS connector, wave W5 (docs/verticals-0.1.85.md "BikeBook connector", DECISIONS "BikeBook connector (W5)").
 * Additive only: six new tables and one nullable column; nothing existing changes. Everything stays empty while
 * WORKSHOP_CONNECTOR is off.
 *
 * - `workshop_connections`: one per shop and provider (WORKSHOP-BIKEBOOK.md §1.1). The API key is stored as an
 *   HMAC + last 4; the webhook secret BikeBook gives us is encrypted at rest.
 * - `workshop_tokens`: OAuth authorisation codes, access and refresh tokens (SHA-256 hash only).
 * - `workshop_orders` / `workshop_order_lines`: BikeBook sales orders, sent to the shop's till as the pull row
 *   `WorkshopOrder` (contract proposal docs/contracts/proposals/workshop-order). `version` rises on every change.
 * - `workshop_call_logs`: the last 1,000 connector calls per connection (no body, token, key or address).
 * - `workshop_webhook_deliveries`: the webhook outbox to BikeBook (exact body, attempts, Replay); last 1,000 kept.
 * - `branches.licence_add_ons`: paid per-shop add-ons on top of the plan's features (`workshop_integration`),
 *   appended to the till's licence token. Null for every shop today, so no token changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->json('licence_add_ons')->nullable();
        });

        Schema::create('workshop_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 20);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('default_store_id', 26)->nullable();
            $table->string('product_scope', 20)->nullable();
            $table->string('webhook_id', 100)->nullable();
            $table->string('webhook_name', 120)->nullable();
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('webhook_subscriptions')->nullable();
            $table->boolean('webhook_enabled')->default(false);
            $table->string('api_key_hash', 64)->nullable();
            $table->string('api_key_last4', 4)->nullable();
            $table->timestamp('api_key_created_at')->nullable();
            $table->string('created_by', 120)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'provider'], 'workshop_connections_branch_provider_unique');
            $table->unique('api_key_hash', 'workshop_connections_api_key_hash_unique');
            $table->index(['company_id', 'status'], 'workshop_connections_company_status_index');
        });

        Schema::create('workshop_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('workshop_connections')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('token_hash', 64);
            $table->string('scopes', 255)->default('');
            $table->text('redirect_uri')->nullable();
            $table->string('parent_id', 26)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'workshop_tokens_token_hash_unique');
            $table->index(['connection_id', 'kind', 'revoked_at'], 'workshop_tokens_connection_kind_index');
        });

        Schema::create('workshop_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('workshop_connections')->cascadeOnDelete();
            $table->string('order_number', 120);
            $table->string('reference', 40);
            $table->string('status', 20);
            $table->string('external_job_number', 60)->default('');
            $table->string('external_job_id', 100)->default('');
            $table->string('external_invoice_id', 100)->default('');
            $table->string('customer_id', 26)->nullable();
            $table->string('external_customer_id', 100)->default('');
            $table->string('customer_name', 200)->default('');
            $table->string('customer_phone', 60)->default('');
            $table->string('customer_email', 255)->default('');
            $table->string('asset_name', 200)->default('');
            $table->string('asset_serial', 100)->default('');
            $table->string('external_asset_id', 100)->default('');
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('goods_total', 12, 2)->default(0);
            $table->decimal('paid_elsewhere', 12, 2)->default(0);
            $table->boolean('completed_in_bikebook')->default(false);
            $table->string('billing_type', 20)->default('customer');
            $table->timestamp('due_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('hub_version')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('reported_status', 20)->nullable();
            $table->decimal('reported_paid_total', 12, 2)->nullable();
            $table->decimal('reported_refunded_total', 12, 2)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'order_number'], 'workshop_orders_connection_number_unique');
            $table->index(['company_id', 'branch_id', 'hub_version'], 'workshop_orders_branch_hub_version_index');
        });

        Schema::create('workshop_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workshop_order_id')->constrained('workshop_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('external_line_id', 100)->default('');
            $table->string('product_id', 26)->nullable();
            $table->string('name', 200);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 12, 2);
            $table->string('vat_rate_id', 26);
            $table->decimal('vat_percentage', 7, 3);
            $table->string('line_type', 20);
            $table->string('external_line_type', 40)->default('');
            $table->decimal('line_total', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->timestamps();

            $table->index(['workshop_order_id', 'position'], 'workshop_order_lines_order_position_index');
        });

        Schema::create('workshop_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('workshop_connections')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('request_id', 100)->nullable();
            $table->string('auth', 10)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['connection_id', 'id'], 'workshop_call_logs_connection_id_index');
        });

        Schema::create('workshop_webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('workshop_connections')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('order_id', 26)->nullable();
            $table->json('subject_ids')->nullable();
            $table->longText('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'created_at'], 'workshop_webhook_deliveries_connection_index');
            $table->index(['delivered_at', 'failed_at', 'next_attempt_at'], 'workshop_webhook_deliveries_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_webhook_deliveries');
        Schema::dropIfExists('workshop_call_logs');
        Schema::dropIfExists('workshop_order_lines');
        Schema::dropIfExists('workshop_orders');
        Schema::dropIfExists('workshop_tokens');
        Schema::dropIfExists('workshop_connections');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('licence_add_ons');
        });
    }
};
