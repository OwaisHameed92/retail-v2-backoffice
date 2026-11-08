<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hub-owned salon tables (salon pack 2026-11-08, W2; written out, never read from EntityRegistry). */
    private const HUB_TABLES = [
        'salon_commission_plans', 'salon_commission_tiers', 'salon_commission_category_rates',
        'salon_commission_fixed_services', 'salon_levels', 'salon_resources', 'salon_service_details',
        'salon_service_level_prices', 'salon_service_add_ons', 'salon_stamp_card_rules', 'salon_package_definitions',
        'salon_consultation_form_templates', 'salon_online_bookings',
    ];

    /**
     * Hand-written next to the generated 2026_12_15_100000 migration: `(company_id, hub_version)` on the new hub-owned
     * salon tables, as 2026_10_05_100000 did for the others (the pull feed reads them by version).
     */
    public function up(): void
    {
        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['company_id', 'hub_version'], self::indexName($name));
            });
        }
    }

    public function down(): void
    {
        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex(self::indexName($name));
            });
        }
    }

    /** Under MySQL's 64 characters. */
    private static function indexName(string $table): string
    {
        return $table.'_company_hub_version_index';
    }
};
