<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shelf-edge labels (gap #6), portal-only: the contract has no print-queue entity (its `ShelfLabel` is an electronic
 * shelf label binding, branch-owned, and the `labels.*` settings are local-only), so these tables never sync.
 *
 * `label_queue_items`: one row per shop and product (the dedupe key); `pending` until printed, re-queued in place.
 * `label_templates`: a shop's (or, `branch_id` null, every shop's) label stock and what each label shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_queue_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->string('product_id', 26);
            $table->boolean('pending')->default(true);
            $table->string('reason', 30);
            $table->string('detail', 190)->nullable();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->unsignedInteger('times_queued')->default(1);
            $table->dateTime('queued_at');
            $table->dateTime('due_at')->nullable();
            $table->unsignedBigInteger('queued_by_user_id')->nullable();
            $table->dateTime('printed_at')->nullable();
            $table->unsignedBigInteger('printed_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['branch_id', 'product_id'], 'label_queue_items_shop_product_unique');
            $table->index(['company_id', 'branch_id', 'pending', 'queued_at'], 'label_queue_items_list_index');
        });

        Schema::create('label_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26)->nullable();
            $table->string('name', 60);
            $table->string('stock', 20);
            $table->json('options');
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['company_id', 'branch_id'], 'label_templates_shop_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_templates');
        Schema::dropIfExists('label_queue_items');
    }
};
