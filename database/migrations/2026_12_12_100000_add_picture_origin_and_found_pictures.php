<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMG-2 (EPOS answer 2026-11-02, till 0.1.83): where a till picture came from, and the store's choice to show the
 * pictures of unknown copyright. Additive, portal only:
 *
 * - `product_images.origin`: ProductImageOrigin from the till's `X-SSPOS-Image-Source` header (`own`, `library`,
 *   `found`, `unknown`). Default `unknown`: every picture held before IMG-2 counts as unknown. Not `source`, which
 *   says till or portal.
 * - `online_stores.show_found_pictures`: the store builder's "Show found pictures". Default false: the public store
 *   shows only the shop's own and Open Food Facts pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('origin', 10)->default('unknown');
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->boolean('show_found_pictures')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn('show_found_pictures');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
