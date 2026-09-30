<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.4 (customers screens, 100k customers per business): indexes for the list's search, sorts and filters,
 * the consent filter (latest row per channel) and the "used at this shop" filter. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['company_id', 'name'], 'customers_company_id_name_index');
            $table->index(['company_id', 'phone'], 'customers_company_id_phone_index');
            $table->index(['company_id', 'email'], 'customers_company_id_email_index');
            $table->index(['company_id', 'balance'], 'customers_company_id_balance_index');
            $table->index(['company_id', 'points'], 'customers_company_id_points_index');
        });

        Schema::table('consents', function (Blueprint $table) {
            $table->index(['company_id', 'customer_id', 'channel', 'at'], 'consents_company_customer_channel_at_index');
        });

        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'customer_id'], 'customer_transactions_company_branch_customer_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->dropIndex('customer_transactions_company_branch_customer_index');
        });

        Schema::table('consents', function (Blueprint $table) {
            $table->dropIndex('consents_company_customer_channel_at_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            foreach (['name', 'phone', 'email', 'balance', 'points'] as $column) {
                $table->dropIndex("customers_company_id_{$column}_index");
            }
        });
    }
};
