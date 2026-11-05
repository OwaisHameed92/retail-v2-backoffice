<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-05, additive only: `billing_accounts.setup_fee_by_direct_debit` (default false) is the per-business
 * exception "Setup fee collected by Direct Debit", set only by `billing.manage` admins in Payment settings. Every
 * existing row gets false, so nothing changes for anyone until staff switch it on. The legacy `setup_fee_method`
 * (`directDebit` on a few old rows) is never read as this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->boolean('setup_fee_by_direct_debit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn('setup_fee_by_direct_debit');
        });
    }
};
