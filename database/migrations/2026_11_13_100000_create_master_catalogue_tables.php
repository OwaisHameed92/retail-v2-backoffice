<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starter catalogue and barcode lookup (gap analysis must-have #7). Platform-wide data, owned by SSPOS staff: no
 * `company_id` on any of these tables.
 *
 * - `master_products`: one row per barcode (EAN-13/8, UPC-A, GTIN-14) with the details a shop would key in, so a new
 *   business picks products instead of typing them. A merged duplicate keeps its row (`merged_into_id`), so a lookup
 *   of its barcode still finds the product it was merged into. `in_starter_packs` marks the curated lines a new
 *   business is offered by shop type (a large licensed file is not offered whole).
 * - `master_product_imports`: an admin CSV load (a licensed supplier file), applied in chunks by a queued job.
 * - `catalogue_contributions`: barcodes tills sold that the catalogue does not know yet, collected anonymously
 *   (barcode, name, size: never a price, a business or a shop) for an admin to approve.
 * - `companies.share_unknown_barcodes`: a business may opt out of contributing (default in).
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('barcode', 14)->unique();
            $table->string('name', 255);
            $table->string('brand', 120)->nullable();
            $table->decimal('size_value', 14, 4)->nullable();
            $table->string('size_unit', 10)->nullable();          // g, kg, ml, cl, l, each, pack
            $table->unsignedSmallInteger('pack_qty')->nullable(); // multipacks: 4 x 440ml
            $table->string('department', 120)->nullable();
            $table->string('category', 120)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();        // suggested percentage: 20, 5 or 0
            $table->decimal('rrp', 12, 2)->nullable();
            $table->string('age_rule', 30)->default('none');      // the till's AgeRule values
            $table->string('image_url', 500)->nullable();
            $table->boolean('in_starter_packs')->default(false);  // offered in the onboarding starter packs
            $table->string('source', 20);                         // starter, import, contribution, admin
            $table->string('source_ref', 255)->nullable();        // file name or supplier
            $table->ulid('merged_into_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('name');
            $table->index(['department', 'name']);
            $table->index(['in_starter_packs', 'department']);
            $table->index('merged_into_id');
        });

        Schema::create('master_product_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('admin_id')->nullable();
            $table->string('file_name', 255);
            $table->string('path', 255);
            $table->string('source_ref', 255)->nullable();
            $table->string('status', 20);                         // queued, running, completed, failed
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('created_at');
        });

        Schema::create('catalogue_contributions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('barcode', 14)->unique();
            $table->string('name', 255);
            $table->decimal('size_value', 14, 4)->nullable();
            $table->string('size_unit', 10)->nullable();
            $table->unsignedSmallInteger('pack_qty')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->string('status', 20)->default('pending');     // pending, approved, rejected
            $table->ulid('master_product_id')->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'seen_count']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('share_unknown_barcodes')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('share_unknown_barcodes');
        });

        Schema::dropIfExists('catalogue_contributions');
        Schema::dropIfExists('master_product_imports');
        Schema::dropIfExists('master_products');
    }
};
