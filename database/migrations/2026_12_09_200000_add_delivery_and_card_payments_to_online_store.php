<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online store, stage S2 (OS-9, docs/online-store.md): local delivery and card payment online with DNA Payments.
 * Additive only; portal-only tables. Nothing changes for a store that turns neither on: every new column defaults to
 * click & collect, pay at the shop.
 *
 * - `online_stores`: the business's own DNA Payments credentials (Laravel encrypted casts: the columns hold
 *   ciphertext only), test mode, and the ways to pay offered per method (null = the S1 default).
 * - `online_store_shops`: delivery per collection shop: on/off, area (postcode districts / sectors), fee, free-over,
 *   minimum order, and its own slots (hours, slot length, orders per slot, lead time).
 * - `online_orders`: collection or delivery, the delivery address, fee and grand total, how it is paid, the card
 *   payment's hold (`payment_expires_at`), result and DNA invoice id, and the two delivery stage times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->text('dna_client_id')->nullable();
            $table->text('dna_client_secret')->nullable();
            $table->text('dna_terminal_id')->nullable();
            $table->boolean('dna_test_mode')->default(true);
            $table->json('collection_payments')->nullable();
            $table->json('delivery_payments')->nullable();
        });

        Schema::table('online_store_shops', function (Blueprint $table) {
            $table->boolean('delivery_enabled')->default(false);
            $table->json('delivery_areas')->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('delivery_free_over', 12, 2)->nullable();
            $table->decimal('delivery_minimum', 12, 2)->nullable();
            $table->json('delivery_hours')->nullable();
            $table->unsignedSmallInteger('delivery_slot_minutes')->default(60);
            $table->unsignedSmallInteger('delivery_orders_per_slot')->default(4);
            $table->unsignedSmallInteger('delivery_lead_minutes')->default(120);
        });

        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('fulfilment', 20)->default('collection');
            $table->string('payment_method', 20)->default('payAtShop');
            $table->string('delivery_line1', 120)->nullable();
            $table->string('delivery_line2', 120)->nullable();
            $table->string('delivery_town', 80)->nullable();
            $table->string('delivery_postcode', 10)->nullable();
            $table->string('delivery_notes', 255)->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->nullable();
            $table->string('payment_invoice_id', 40)->nullable();
            $table->dateTime('payment_expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_error', 255)->nullable();
            $table->dateTime('out_for_delivery_at')->nullable();
            $table->dateTime('delivered_at')->nullable();

            $table->unique('payment_invoice_id', 'online_orders_payment_invoice_unique');
            $table->index(['status', 'payment_expires_at'], 'online_orders_payment_hold_index');
        });

        // Orders placed before S2: collected, paid at the shop, nothing to deliver.
        DB::table('online_orders')->whereNull('grand_total')->update(['grand_total' => DB::raw('items_total')]);
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropUnique('online_orders_payment_invoice_unique');
            $table->dropIndex('online_orders_payment_hold_index');
            $table->dropColumn([
                'fulfilment', 'payment_method', 'delivery_line1', 'delivery_line2', 'delivery_town', 'delivery_postcode', 'delivery_notes',
                'delivery_fee', 'grand_total', 'payment_invoice_id', 'payment_expires_at', 'paid_at', 'paid_amount', 'payment_reference',
                'payment_error', 'out_for_delivery_at', 'delivered_at',
            ]);
        });

        Schema::table('online_store_shops', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_enabled', 'delivery_areas', 'delivery_fee', 'delivery_free_over', 'delivery_minimum', 'delivery_hours',
                'delivery_slot_minutes', 'delivery_orders_per_slot', 'delivery_lead_minutes',
            ]);
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn(['dna_client_id', 'dna_client_secret', 'dna_terminal_id', 'dna_test_mode', 'collection_payments', 'delivery_payments']);
        });
    }
};
