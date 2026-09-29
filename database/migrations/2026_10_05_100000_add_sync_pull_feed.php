<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hub-owned till tables (samples/ownership.json "hub", the ones with a table). Written out, not read from
     * EntityRegistry, so this migration never changes when the registry does.
     */
    private const HUB_TABLES = [
        'ledger_accounts', 'categories', 'customers', 'departments', 'exchange_rates', 'fixed_asset_categories',
        'medicine_classifications', 'news_titles', 'payment_types', 'price_histories', 'products', 'product_aliases',
        'product_barcodes', 'product_recalls', 'product_suppliers', 'product_units', 'promotion_coupons',
        'promotion_items', 'promotion_rules', 'reasons', 'rebate_agreements', 'till_roles', 'suppliers', 'tax_rules',
        'units', 'till_users', 'vat_rates',
    ];

    /**
     * Module 2.5 (pull, contract v1.3.3 §8, §19):
     *
     * - `sync_hub_counters`: one row per company, the last pull `version` handed out. Incremented under the row's
     *   lock (HubVersions), so versions are unique, increasing and visible in order.
     * - `(company_id, hub_version)` on every hub-owned table: the pull reads rows after `since`, and finds rows not
     *   stamped yet (`hub_version` null).
     * - `sync_branch_status`: when the branch last pulled, from which version, the highest version sent, rows sent.
     */
    public function up(): void
    {
        Schema::create('sync_hub_counters', function (Blueprint $table) {
            $table->ulid('company_id')->primary();
            $table->unsignedBigInteger('last_version')->default(0);
            $table->dateTime('updated_at')->nullable();
        });

        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['company_id', 'hub_version'], $name.'_company_hub_version_index');
            });
        }

        Schema::table('sync_branch_status', function (Blueprint $table) {
            $table->dateTime('last_pull_at')->nullable()->after('last_push_at');
            $table->unsignedBigInteger('last_pull_since')->nullable()->after('last_pull_at');
            $table->unsignedBigInteger('last_pull_version')->nullable()->after('last_pull_since');
            $table->unsignedInteger('last_pull_rows')->nullable()->after('last_pull_version');
        });
    }

    public function down(): void
    {
        Schema::table('sync_branch_status', function (Blueprint $table) {
            $table->dropColumn(['last_pull_at', 'last_pull_since', 'last_pull_version', 'last_pull_rows']);
        });

        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_company_hub_version_index');
            });
        }

        Schema::dropIfExists('sync_hub_counters');
    }
};
