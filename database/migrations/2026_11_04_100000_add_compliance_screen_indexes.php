<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.7 (compliance): the screens read age refusals and exceptions by time across every shop, voided lines by
 * audit action and time, and training and licences by expiry date (the till's indexes lead with the shop or have no
 * date). Additive.
 */
return new class extends Migration
{
    /** @var array<string, array{string, list<string>}> table → [index name, columns] */
    private const INDEXES = [
        'age_refusals' => ['age_refusals_company_id_at_index', ['company_id', 'at']],
        'exception_logs' => ['exception_logs_company_id_at_index', ['company_id', 'at']],
        'till_audit_logs' => ['till_audit_logs_company_id_action_at_index', ['company_id', 'action', 'at']],
        'training_records' => ['training_records_company_id_expires_on_index', ['company_id', 'expires_on']],
        'compliance_licences' => ['compliance_licences_company_id_expires_on_index', ['company_id', 'expires_on']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
