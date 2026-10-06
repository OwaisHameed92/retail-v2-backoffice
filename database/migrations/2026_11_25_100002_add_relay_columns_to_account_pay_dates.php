<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Till 0.1.52: `AccountPayDate` is relayed to every other branch (ownership.json `relayed`, contract §10.2), so it
     * gets the relay columns of 2026_10_08_100000: `hub_version` (null = not stamped yet, so every existing pay date is
     * relayed once, as a new branch gets the whole ledger) and `origin_branch_id` (the shop whose push wrote it).
     * Written out, never read from EntityRegistry.
     */
    public function up(): void
    {
        Schema::table('account_pay_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('hub_version')->nullable();
            $table->string('origin_branch_id', 26)->nullable();
            $table->index(['company_id', 'hub_version'], 'account_pay_dates_company_hub_version_index');
        });
    }

    public function down(): void
    {
        Schema::table('account_pay_dates', function (Blueprint $table) {
            $table->dropIndex('account_pay_dates_company_hub_version_index');
            $table->dropColumn(['hub_version', 'origin_branch_id']);
        });
    }
};
