<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing showcase (`demo:billing`), additive only: `companies.is_demo` marks a made-up business. A demo business is
 * never sent to GoCardless and never gets an email (logged as "suppressed" instead), and `demo:billing --fresh`
 * removes only these businesses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
