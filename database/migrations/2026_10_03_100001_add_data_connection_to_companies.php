<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sharding groundwork (docs/scaling.md): the database connection that holds a company's data. Everyone is on
     * the default connection today; nothing routes through it yet (App\Domain\Tenancy\Support\CompanyConnection).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('data_connection', 40)->default((string) config('database.default'));
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('data_connection');
        });
    }
};
