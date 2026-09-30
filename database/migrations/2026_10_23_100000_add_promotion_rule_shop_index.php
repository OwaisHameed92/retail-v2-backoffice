<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.3: the offers list filters by shop (`branch_id`, null = every shop) and start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_rules', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'effective_from'], 'promotion_rules_shop_from_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_rules', function (Blueprint $table) {
            $table->dropIndex('promotion_rules_shop_from_idx');
        });
    }
};
