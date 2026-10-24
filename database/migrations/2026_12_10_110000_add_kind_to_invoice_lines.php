<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit OSA-30: an explicit `kind` on invoice lines (InvoiceLineKind: licence, branch, onlineStore, manual), so the
 * online store add-on line is no longer recognised by "no licence and no branch". Additive; existing lines are
 * filled in where the kind is certain (a licence, a branch, the add-on's "Online store · …" line); any other old
 * line stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('branch_id');
        });

        DB::table('invoice_lines')->whereNull('kind')->whereNotNull('licence_id')->update(['kind' => 'licence']);
        DB::table('invoice_lines')->whereNull('kind')->whereNull('licence_id')->whereNotNull('branch_id')->update(['kind' => 'branch']);
        DB::table('invoice_lines')->whereNull('kind')->whereNull('licence_id')->whereNull('branch_id')
            ->where('description', 'like', 'Online store · %')->update(['kind' => 'onlineStore']);
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
