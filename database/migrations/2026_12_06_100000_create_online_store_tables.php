<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online store, stage S1: click & collect, pay at the shop (docs/online-store.md, module OS-1). Additive only;
 * portal-only tables, never sent to or written by a till. Till tables (products, prices, stock) are only read.
 *
 * - `online_stores`: one per business; `slug` is the store's address, unique across every business.
 * - `online_store_shops`: the shops customers collect from, with opening hours per weekday (shop time), prep
 *   minutes, slot length and orders per slot.
 * - `online_store_products`: per till product (`product_id` = the till's id): hidden online, photo, description.
 * - `online_orders` / `online_order_lines`: guest orders. `number` is per business (W-0001…); `token_hash` is the
 *   sha256 of the order page's secret link (the plain token is never stored). Times are UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_stores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('slug', 40);
            $table->string('template', 20)->default('freshMarket');
            $table->string('logo_path', 255)->nullable();
            $table->string('banner_path', 255)->nullable();
            $table->string('brand_colour', 7)->nullable();
            $table->string('headline', 160)->nullable();
            $table->json('sections')->nullable();
            $table->boolean('is_live')->default(false);
            $table->boolean('taking_orders')->default(false);
            $table->string('order_email', 255)->nullable();
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->dateTime('live_at')->nullable();
            $table->timestamps();

            $table->unique('company_id', 'online_stores_company_unique');
            $table->unique('slug', 'online_stores_slug_unique');
        });

        Schema::create('online_store_shops', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_id')->constrained('online_stores')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->json('opening_hours')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->default(30);
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('orders_per_slot')->default(5);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['online_store_id', 'branch_id'], 'online_store_shops_store_branch_unique');
            $table->index(['company_id', 'branch_id'], 'online_store_shops_company_branch_index');
        });

        Schema::create('online_store_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('product_id', 26);
            $table->boolean('hidden')->default(false);
            $table->string('photo_path', 255)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'online_store_products_company_product_unique');
        });

        Schema::create('online_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_id')->constrained('online_stores')->cascadeOnDelete();
            $table->foreignUlid('online_store_shop_id')->constrained('online_store_shops');
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->unsignedInteger('number');
            $table->string('status', 20)->default('new');
            $table->string('customer_name', 120);
            $table->string('customer_mobile', 30);
            $table->string('customer_email', 255);
            $table->dateTime('collect_from');
            $table->dateTime('collect_to');
            $table->decimal('items_total', 12, 2);
            $table->char('token_hash', 64);
            $table->string('cancel_reason', 255)->nullable();
            $table->dateTime('picked_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'online_orders_company_number_unique');
            $table->unique('token_hash', 'online_orders_token_hash_unique');
            $table->index(['company_id', 'status'], 'online_orders_company_status_index');
            $table->index(['online_store_shop_id', 'collect_from'], 'online_orders_shop_slot_index');
        });

        Schema::create('online_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_order_id')->constrained('online_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('product_id', 26);
            $table->string('name', 255);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 14, 4);
            $table->boolean('is_weighed')->default(false);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['online_order_id', 'position'], 'online_order_lines_order_index');
            $table->index(['company_id', 'product_id'], 'online_order_lines_company_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_lines');
        Schema::dropIfExists('online_orders');
        Schema::dropIfExists('online_store_products');
        Schema::dropIfExists('online_store_shops');
        Schema::dropIfExists('online_stores');
    }
};
