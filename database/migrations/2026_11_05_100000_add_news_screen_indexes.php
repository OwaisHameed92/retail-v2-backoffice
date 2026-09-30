<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.8 (newspapers): indexes for news deliveries by date (every shop or one shop), their lines by title, and
 * vouchers by redemption time across shops. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_deliveries', function (Blueprint $table) {
            $table->index(['company_id', 'delivery_date'], 'news_deliveries_company_date_index');
            $table->index(['company_id', 'branch_id', 'delivery_date'], 'news_deliveries_company_branch_date_index');
        });

        Schema::table('news_delivery_lines', function (Blueprint $table) {
            $table->index(['company_id', 'title_id'], 'news_delivery_lines_company_title_index');
        });

        Schema::table('news_voucher_redemptions', function (Blueprint $table) {
            $table->index(['company_id', 'redeemed_at'], 'news_voucher_redemptions_company_redeemed_index');
        });
    }

    public function down(): void
    {
        Schema::table('news_deliveries', function (Blueprint $table) {
            $table->dropIndex('news_deliveries_company_date_index');
            $table->dropIndex('news_deliveries_company_branch_date_index');
        });

        Schema::table('news_delivery_lines', function (Blueprint $table) {
            $table->dropIndex('news_delivery_lines_company_title_index');
        });

        Schema::table('news_voucher_redemptions', function (Blueprint $table) {
            $table->dropIndex('news_voucher_redemptions_company_redeemed_index');
        });
    }
};
