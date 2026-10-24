<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online store audit fixes in checkout and orders (OS-9; docs/audit/2026-10-24-online-store.md). Additive only;
 * portal-only tables.
 *
 * - `online_orders`: the mobile as digits (`customer_mobile_key`, for the per-customer limits, OSA-1), "cancelled as
 *   spam" (no email), the customer's note and substitution choice (OSA-18), the last move for undo and "not
 *   collected" (OSA-21), and when the customer's details were anonymised (OSA-22).
 * - `online_order_lines`: what the shop did with a line while picking (OSA-18): changed or not available, with the
 *   quantity first ordered.
 * - `online_store_closures`: closed days and special hours per shop for collection and delivery (OSA-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('customer_mobile_key', 20)->nullable();
            $table->boolean('cancelled_as_spam')->default(false);
            $table->string('customer_note', 500)->nullable();
            $table->boolean('substitutions_ok')->default(false);
            $table->string('previous_status', 20)->nullable();
            $table->dateTime('status_changed_at')->nullable();
            $table->dateTime('not_collected_at')->nullable();
            $table->dateTime('anonymised_at')->nullable();

            $table->index(['company_id', 'customer_email'], 'online_orders_company_email_index');
            $table->index(['company_id', 'customer_mobile_key'], 'online_orders_company_mobile_index');
        });

        Schema::table('online_order_lines', function (Blueprint $table) {
            $table->string('outcome', 20)->nullable();
            $table->decimal('ordered_quantity', 14, 4)->nullable();
        });

        Schema::create('online_store_closures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_shop_id')->constrained('online_store_shops')->cascadeOnDelete();
            $table->date('date');
            $table->string('opens_at', 5)->nullable();
            $table->string('closes_at', 5)->nullable();
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->unique(['online_store_shop_id', 'date'], 'online_store_closures_shop_date_unique');
            $table->index(['company_id', 'date'], 'online_store_closures_company_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_closures');

        Schema::table('online_order_lines', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'ordered_quantity']);
        });

        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropIndex('online_orders_company_email_index');
            $table->dropIndex('online_orders_company_mobile_index');
            $table->dropColumn(['customer_mobile_key', 'cancelled_as_spam', 'customer_note', 'substitutions_ok', 'previous_status',
                'status_changed_at', 'not_collected_at', 'anonymised_at']);
        });
    }
};
