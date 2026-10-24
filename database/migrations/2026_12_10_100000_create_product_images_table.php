<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMG-1 (contract proposal docs/contracts/proposals/product-images): the pictures of till products the portal holds.
 * Portal-only, never sent to or written by a till as a row. Additive only.
 *
 * - `product_id`: the till product's id as the till sent it (`products.id`), no foreign key (as online_store_products).
 * - `source`: `till` (uploaded by the till, `PUT sync/product-images/{productId}`) or `portal` (later phase).
 * - `hash`: sha256 (hex) of the bytes the till sent: the idempotency key and what the till compares.
 * - `path`: the re-encoded web copy on the public disk, `till-images/{company_id}/{product_id}-{hash16}.webp|jpg`;
 *   `bytes`, `width`, `height` describe that copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('product_id', 26);
            $table->string('source', 10)->default('till');
            $table->string('path', 255);
            $table->char('hash', 64);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'source'], 'product_images_company_product_source_unique');
            $table->index(['company_id', 'source', 'product_id'], 'product_images_company_source_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
