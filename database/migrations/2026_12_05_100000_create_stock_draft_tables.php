<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock documents drafted on the portal for one shop (contract proposal docs/contracts/proposals/pda-stock-drafts,
 * DECISIONS "Handheld stock drafts"). Additive only; portal-only tables (never written by a push, never a till
 * table). Rows are only made while `PDA_STOCK_DRAFTS` is on.
 *
 * - `stock_drafts`: one document (kind adjustment / stockTake / goodsReceipt / purchaseReturn). Its id is the id the
 *   till gets. `state` pending (the portal may change it) → withTill (sent in a pull) → confirmed / rejected (the
 *   till's push), or cancelled before it was sent. `hub_version` is the pull counter value. `client_reference` is
 *   the handheld's idempotency UUID, unique per shop and kind; `number` the per-shop HS-/HR- number.
 * - `stock_draft_lines`: its lines (stock take counts, delivery quantities per order line, returned quantities).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->string('kind', 20);
            $table->string('state', 20)->default('pending');
            $table->ulid('handheld_user_id')->nullable();
            $table->string('entered_by', 120);
            $table->string('client_reference', 64)->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('reference', 40)->nullable();
            $table->ulid('product_id')->nullable();
            $table->string('adjustment_type', 20)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->string('reason', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->ulid('product_unit_id')->nullable();
            $table->decimal('entered_quantity', 14, 4)->nullable();
            $table->decimal('conversion_to_base', 14, 4)->nullable();
            $table->decimal('stock_on_hand_before', 14, 4)->nullable();
            $table->string('name', 120)->nullable();
            $table->ulid('supplier_id')->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->ulid('purchase_order_id')->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->dateTime('happened_at');
            $table->unsignedBigInteger('hub_version')->nullable();
            $table->dateTime('pulled_at')->nullable();
            $table->dateTime('outcome_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->decimal('qty_before', 14, 4)->nullable();
            $table->decimal('qty_delta', 14, 4)->nullable();
            $table->decimal('qty_after', 14, 4)->nullable();
            $table->ulid('stock_movement_id')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'kind', 'client_reference'], 'stock_drafts_shop_kind_ref_unique');
            $table->unique(['company_id', 'branch_id', 'kind', 'number'], 'stock_drafts_shop_kind_number_unique');
            $table->index(['company_id', 'hub_version'], 'stock_drafts_company_version_index');
            $table->index(['company_id', 'branch_id', 'kind', 'state'], 'stock_drafts_shop_kind_state_index');
            $table->index(['company_id', 'purchase_order_id'], 'stock_drafts_order_index');
        });

        Schema::create('stock_draft_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('stock_draft_id')->constrained('stock_drafts')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->ulid('product_id');
            $table->string('product_name', 255);
            $table->ulid('purchase_order_line_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('expected_quantity', 14, 4)->nullable();
            $table->decimal('variance', 14, 4)->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->ulid('vat_rate_id')->nullable();
            $table->decimal('vat_percentage', 14, 4)->nullable();
            $table->unsignedInteger('case_qty')->nullable();
            $table->string('reason', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('hub_version')->nullable();
            $table->timestamps();

            $table->index(['stock_draft_id', 'position'], 'stock_draft_lines_draft_index');
            $table->index(['company_id', 'hub_version'], 'stock_draft_lines_version_index');
            $table->index('purchase_order_line_id', 'stock_draft_lines_order_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_draft_lines');
        Schema::dropIfExists('stock_drafts');
    }
};
