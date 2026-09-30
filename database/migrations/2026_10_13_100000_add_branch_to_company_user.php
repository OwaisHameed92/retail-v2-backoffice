<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3.3: a membership may be limited to one shop (the branch-scoped shop manager of DASHBOARD.md §1.9, §2.1;
 * screens to set it come in 4.1). Null = every shop of the business. No foreign key on purpose: removing the shop
 * must never widen the user to every shop (a missing shop shows nothing, fail closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->char('branch_id', 26)->nullable()->after('role');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
