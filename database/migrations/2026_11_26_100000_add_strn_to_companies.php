<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pakistan plan P3: a business's STRN (sales tax registration number, 13 digits) on a PK instance, beside its NTN
 * (kept in `vat_number`). Additive and nullable: existing rows are untouched and GB never shows or writes it. Not a
 * till contract field (the till's Company has no STRN yet; EPOS adds it with FBR, phase P8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('strn', 20)->nullable()->after('company_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('strn');
        });
    }
};
