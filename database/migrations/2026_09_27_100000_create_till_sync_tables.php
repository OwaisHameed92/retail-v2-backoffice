<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 2.3/2.4 sync bookkeeping (docs/till-data.md).
     *
     * sync_applied_changes: one row per pushed change the store accepted, keyed by (company, sending branch, seq)
     * so a retried batch is recognised and gives the same reply. No foreign keys (rows arrive out of order).
     *
     * sync_conflicts: changes a person (or module 2.5) must look at: a till edit of a hub-owned row the portal
     * edited more recently, a change to an immutable historic row, a delete of a portal-owned tenancy row.
     */
    public function up(): void
    {
        Schema::create('sync_applied_changes', function (Blueprint $table) {
            $table->id();
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->unsignedBigInteger('seq');
            $table->string('entity', 64);
            $table->string('entity_id', 26);
            $table->unsignedBigInteger('version');
            $table->char('op', 1);
            $table->string('outcome', 16);
            $table->dateTime('applied_at');

            $table->unique(['company_id', 'branch_id', 'seq'], 'sync_applied_changes_branch_seq_unique');
            $table->index(['company_id', 'entity', 'entity_id', 'version'], 'sync_applied_changes_entity_index');
            $table->index('applied_at');
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26)->nullable();
            $table->string('entity', 64);
            $table->string('entity_id', 26);
            $table->string('kind', 32);
            $table->unsignedBigInteger('local_version')->nullable();
            $table->unsignedBigInteger('incoming_version');
            $table->unsignedBigInteger('incoming_seq')->nullable();
            $table->dateTime('incoming_at')->nullable();
            $table->longText('incoming_payload')->nullable();
            $table->string('detail', 1000)->nullable();
            $table->string('status', 16)->default('open');
            $table->string('resolution', 32)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolved_by', 26)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'sync_conflicts_company_status_index');
            $table->index(['company_id', 'entity', 'entity_id'], 'sync_conflicts_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_applied_changes');
    }
};
