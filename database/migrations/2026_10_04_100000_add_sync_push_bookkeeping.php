<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 2.2 (hello and push, contract v1.3.3 §4, §7, §17.8):
     *
     * - `sync_branch_status`: one row per branch for module 2.7 (Till health): last hello and push, last
     *   acknowledged seq, rows accepted/rejected on the current London day, the last error, the till's app version
     *   and sending register.
     * - `sync_applied_changes.stream`: '' for the branch's ChangeLog (delta push), else the initial upload's id. An
     *   initial upload numbers its rows 1…N (§17.8, keyed by (uploadId, seq)), so it must not share the delta seqs.
     */
    public function up(): void
    {
        Schema::create('sync_branch_status', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->ulid('branch_id')->unique();
            $table->dateTime('last_hello_at')->nullable();
            $table->dateTime('last_push_at')->nullable();
            $table->unsignedBigInteger('last_acknowledged_seq')->nullable();
            $table->char('last_upload_id', 26)->nullable();
            $table->unsignedBigInteger('last_upload_seq')->nullable();
            $table->date('rows_day')->nullable();
            $table->unsignedInteger('rows_accepted_today')->default(0);
            $table->unsignedInteger('rows_rejected_today')->default(0);
            $table->dateTime('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->string('last_rejected_key', 160)->nullable();
            $table->string('last_app_version', 40)->nullable();
            $table->char('last_register_id', 26)->nullable();
            $table->string('last_till_register_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::table('sync_applied_changes', function (Blueprint $table) {
            $table->string('stream', 26)->default('')->after('branch_id');
        });

        Schema::table('sync_applied_changes', function (Blueprint $table) {
            $table->dropUnique('sync_applied_changes_branch_seq_unique');
            $table->unique(['company_id', 'branch_id', 'stream', 'seq'], 'sync_applied_changes_stream_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sync_applied_changes', function (Blueprint $table) {
            $table->dropUnique('sync_applied_changes_stream_seq_unique');
        });

        Schema::table('sync_applied_changes', function (Blueprint $table) {
            $table->dropColumn('stream');
            $table->unique(['company_id', 'branch_id', 'seq'], 'sync_applied_changes_branch_seq_unique');
        });

        Schema::dropIfExists('sync_branch_status');
    }
};
