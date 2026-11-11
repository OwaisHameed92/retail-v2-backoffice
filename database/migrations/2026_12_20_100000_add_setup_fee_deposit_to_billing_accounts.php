<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-11 (Asia Halal Food Stores), additive only: `billing_accounts.setup_fee_deposit` (net pounds,
 * nullable) is the first instalment of a setup fee paid in instalments; the rest is split equally across the other
 * instalments. Every existing row gets null, so every business keeps its equal split until staff set a deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->decimal('setup_fee_deposit', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn('setup_fee_deposit');
        });
    }
};
