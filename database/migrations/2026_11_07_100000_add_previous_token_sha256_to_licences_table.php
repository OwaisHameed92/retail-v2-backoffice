<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security review H2 (7.3): `licence/validate` must be sent with the token the till holds (contract v1.4.1
 * §17.15.2: the till is identified by `licenceId` + `tokenSha256` + `installId`). The hash of the token issued
 * before the current one is kept too, so a till whose last reply (carrying a new token) was lost still validates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->char('previous_token_sha256', 64)->nullable()->after('token_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn('previous_token_sha256');
        });
    }
};
