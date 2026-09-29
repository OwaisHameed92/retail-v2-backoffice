<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Branch-owned tables the portal relays to another branch (ownership.json `relayed`, contract v1.4 §10.2). */
    private const RELAYED = [
        'stock_transfers', 'stock_transfer_lines', 'stock_transfer_receipts', 'stock_transfer_receipt_lines',
        'customer_transactions',
    ];

    /** Branch-owned tables the portal may draft rows into for one shop (ownership.json `hubDrafted`, §10.6). */
    private const DRAFTED = ['purchase_orders', 'purchase_order_lines'];

    /**
     * Module 2.9B (contract v1.4.1 §6.1, §10.2, §10.6). Written out, never read from EntityRegistry.
     *
     * - Relayed tables: `hub_version` (the pull version; null = not stamped yet, so every existing row is relayed
     *   once, as a new branch gets the whole ledger) and `origin_branch_id` (the shop whose push wrote it).
     * - Drafted tables: the same, plus `hub_drafted_at` (a head-office order drafted on the portal). A shop's own
     *   rows get `hub_version` 0 (never in the feed), so existing rows default to 0.
     * - `companies`, `branches`: `hub_version` for portal edits of their details sent in the pull; default 0 (not
     *   pending) so existing rows are not sent until the portal next edits them.
     */
    public function up(): void
    {
        foreach (self::RELAYED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('hub_version')->nullable();
                $table->string('origin_branch_id', 26)->nullable();
                $table->index(['company_id', 'hub_version'], $name.'_company_hub_version_index');
            });
        }

        foreach (self::DRAFTED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('hub_version')->nullable()->default(0);
                $table->string('origin_branch_id', 26)->nullable();
                $table->dateTime('hub_drafted_at')->nullable();
                $table->index(['company_id', 'hub_version'], $name.'_company_hub_version_index');
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('hub_version')->nullable()->default(0);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('hub_version')->nullable()->default(0);
            $table->index(['company_id', 'hub_version'], 'branches_company_hub_version_index');
        });

        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->string('resolution_note', 500)->nullable();
            $table->index(['company_id', 'kind'], 'sync_conflicts_company_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->dropIndex('sync_conflicts_company_kind_index');
            $table->dropColumn('resolution_note');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_company_hub_version_index');
            $table->dropColumn('hub_version');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('hub_version');
        });

        foreach ([...self::RELAYED, ...self::DRAFTED] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_company_hub_version_index');
                $table->dropColumn(in_array($name, self::DRAFTED, true)
                    ? ['hub_version', 'origin_branch_id', 'hub_drafted_at']
                    : ['hub_version', 'origin_branch_id']);
            });
        }
    }
};
