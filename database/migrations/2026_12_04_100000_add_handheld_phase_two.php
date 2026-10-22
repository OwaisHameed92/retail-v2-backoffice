<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handheld (PDA) API, phase 2 portal part (docs/pda-api.md, DECISIONS "Handheld (PDA) API, phase 2"). Additive only.
 *
 * - `handheld_users.can_change_cost`: may change a product's cost price from the handheld (default no).
 * - `handheld_users.can_adjust_stock`: may adjust stock (default no). Read by the app now; the stock calls answer 501
 *   until the till contract has portal-drafted stock documents.
 * - `handheld_order_references`: the app's `client_reference` (a UUID) per shop for `POST /api/pda/purchase-orders`,
 *   so a repeated create answers the order already made. Unique per business + shop (race-safe); no foreign key to
 *   the till's `purchase_orders` table, which stays as the till contract defines it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handheld_users', function (Blueprint $table) {
            $table->boolean('can_change_cost')->default(false);
            $table->boolean('can_adjust_stock')->default(false);
        });

        Schema::create('handheld_order_references', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->string('client_reference', 64);
            $table->ulid('purchase_order_id')->nullable();
            $table->ulid('handheld_user_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'client_reference'], 'handheld_order_refs_shop_reference_unique');
            $table->index('purchase_order_id', 'handheld_order_refs_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handheld_order_references');

        Schema::table('handheld_users', function (Blueprint $table) {
            $table->dropColumn(['can_change_cost', 'can_adjust_stock']);
        });
    }
};
