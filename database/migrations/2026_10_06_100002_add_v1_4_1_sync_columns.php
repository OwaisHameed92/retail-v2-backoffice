<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hub-owned till tables new in contract v1.4.1 (written out, never read from EntityRegistry). */
    private const HUB_TABLES = ['branch_prices', 'till_settings', 'till_role_permissions'];

    /**
     * Contract v1.4.1, hand-written next to the generated 2026_10_06_1000{00,01} migrations:
     *
     * - `registers.next_order_no`: each till numbers its customer orders from its own counter (§10.4,
     *   `Register.nextOrderNo`), pushed with the Register row like `nextSaleNo`; display only.
     * - `(company_id, hub_version)` on the new hub-owned tables, as 2026_10_05_100000 did for the others (pull feed).
     */
    public function up(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            $table->unsignedInteger('next_order_no')->nullable();
        });

        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['company_id', 'hub_version'], $name.'_company_hub_version_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::HUB_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_company_hub_version_index');
            });
        }

        Schema::table('registers', function (Blueprint $table) {
            $table->dropColumn('next_order_no');
        });
    }
};
