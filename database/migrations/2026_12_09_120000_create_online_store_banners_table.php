<?php

use App\Domain\Shared\Support\Ulid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online store OS-8 (docs/online-store.md): several home banners and real featured products. Additive only;
 * portal-only data, never sent to or written by a till.
 *
 * - `online_store_banners`: up to 6 slides per store (photo, headline, short text, button and where it goes, on or
 *   off, order). A store's single banner photo and headline (`online_stores.banner_path` / `headline`) move into its
 *   first banner; the old columns stay (read as a fallback when a store has no banners).
 * - `online_stores.featured_mode` (bestSellers, handPicked, newest; null = best sellers), `featured_product_ids`
 *   (the hand-picked till product ids, in order) and `home_departments` (the department ids with their own home row).
 * - `online_stores.about_text`: the business's own "About us" text (audit OSA-29).
 * - `online_store_products.allergen_info`: ingredients and allergens shown before purchase (audit OSA-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_banners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_id')->constrained('online_stores')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('photo_path', 255)->nullable();
            $table->string('headline', 160)->nullable();
            $table->string('text', 240)->nullable();
            $table->string('button_label', 40)->nullable();
            $table->string('link_type', 20)->default('none');
            $table->string('link_target', 500)->nullable();
            $table->boolean('is_on')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'online_store_id', 'position'], 'online_store_banners_store_position_index');
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->string('featured_mode', 20)->nullable();
            $table->json('featured_product_ids')->nullable();
            $table->json('home_departments')->nullable();
            $table->string('about_text', 600)->nullable();
        });

        Schema::table('online_store_products', function (Blueprint $table) {
            $table->text('allergen_info')->nullable();
        });

        $now = now('UTC')->format('Y-m-d H:i:s');

        DB::table('online_stores')->where(fn ($q) => $q->whereNotNull('banner_path')->orWhereNotNull('headline'))
            ->orderBy('id')->each(function (object $store) use ($now) {
                DB::table('online_store_banners')->insert([
                    'id' => Ulid::new(), 'company_id' => $store->company_id, 'online_store_id' => $store->id, 'position' => 0,
                    'photo_path' => $store->banner_path, 'headline' => $store->headline, 'link_type' => 'none', 'is_on' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('online_store_products', function (Blueprint $table) {
            $table->dropColumn('allergen_info');
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn(['featured_mode', 'featured_product_ids', 'home_departments', 'about_text']);
        });

        Schema::dropIfExists('online_store_banners');
    }
};
