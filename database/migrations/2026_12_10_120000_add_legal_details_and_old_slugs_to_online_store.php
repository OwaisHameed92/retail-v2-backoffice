<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online store audit fixes OS-11 (docs/audit/2026-10-24-online-store.md). Additive only; portal-only tables.
 *
 * - `online_stores`: the trader's details for the legal pages, footer and checkout (OSA-2): trading name, legal
 *   entity name, registered address, company number, VAT number, contact email and phone, and the shop's own extra
 *   text per legal page (`legal_extra`, JSON: contact, privacy, terms, cookies).
 * - `online_stores.aisle_source` / `aisles`: what the store's aisles are built from (departments, categories, or
 *   null = the better of the two) and the shop's choices per aisle (order, shown or not, display name, photo).
 * - `online_store_products.not_featured`: the shop keeps a product out of the automatic featured row.
 * - `online_store_old_slugs`: a store's previous addresses, redirected (301) to the current one and held from other
 *   businesses until `expires_at` (90 days, OSA-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->string('trading_name', 120)->nullable();
            $table->string('legal_name', 160)->nullable();
            $table->string('legal_address', 500)->nullable();
            $table->string('company_number', 20)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->json('legal_extra')->nullable();
            $table->string('aisle_source', 20)->nullable();
            $table->json('aisles')->nullable();
        });

        Schema::table('online_store_products', function (Blueprint $table) {
            $table->boolean('not_featured')->default(false);
        });

        Schema::create('online_store_old_slugs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('online_store_id')->constrained('online_stores')->cascadeOnDelete();
            $table->string('slug', 40)->unique('online_store_old_slugs_slug_unique');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['online_store_id', 'expires_at'], 'online_store_old_slugs_store_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_old_slugs');

        Schema::table('online_store_products', function (Blueprint $table) {
            $table->dropColumn('not_featured');
        });

        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn(['trading_name', 'legal_name', 'legal_address', 'company_number', 'vat_number', 'contact_email', 'contact_phone', 'legal_extra',
                'aisle_source', 'aisles']);
        });
    }
};
