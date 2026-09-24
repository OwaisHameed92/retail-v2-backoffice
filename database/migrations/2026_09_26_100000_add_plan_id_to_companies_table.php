<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.3: the plan new tills of this company are licensed on. Null = the portal default plan
     * (config licence.default_plan).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->nullable()->after('status')->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
