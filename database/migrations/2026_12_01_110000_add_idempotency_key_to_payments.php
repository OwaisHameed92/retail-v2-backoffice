<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit BILL-9: the Record payment and Record setup fee payment forms send a key made when the form opens; a
     * resubmitted form (double click, browser retry) finds the payment already recorded with that key. Additive and
     * nullable: existing payments keep null (a unique index allows any number of nulls on SQLite and MySQL).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('gateway_reference');
            $table->unique(['company_id', 'idempotency_key'], 'payments_company_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_company_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
