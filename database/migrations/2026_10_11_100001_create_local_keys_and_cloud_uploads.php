<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 2.8 (contract v1.4.1 §17.6, §17.8, §17.10, §17.16):
     *
     * - `local_licence_keys`: the local key register, one row per licence-generator `licenceId` → the install code
     *   that reported it first, with the token's fields (never the token itself: it is not secret, but the hash is
     *   enough for support). `company_id`/`branch_id` = our business and shop when we can tell (id map, sync key);
     *   null for a shop we do not know. Admin data, not tenant-scoped.
     * - `local_licence_key_refusals`: every 409 key.used_on_another_install sent (which install tried, when).
     * - `cloud_uploads`: a shop's move to the cloud (`cloud/migrate`): the upload id, what the till said it would
     *   send, what arrived, and when `migrate/complete` found it whole. One per (branch, install).
     */
    public function up(): void
    {
        Schema::create('local_licence_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('licence_id', 26)->unique();
            $table->string('install_code', 9);
            $table->string('install_id', 26)->nullable();
            $table->string('kid', 64);
            $table->string('issuer', 120)->nullable();
            $table->string('kind', 16)->nullable();
            $table->char('token_sha256', 64);
            $table->string('claimed_company_id', 26)->nullable();
            $table->string('claimed_branch_id', 26)->nullable();
            $table->string('business_name', 100)->nullable();
            $table->string('branch_name', 100)->nullable();
            $table->json('company')->nullable();
            $table->unsignedInteger('max_registers')->nullable();
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('company_id', 26)->nullable()->index();
            $table->string('branch_id', 26)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('os', 120)->nullable();
            $table->string('reported_via', 16);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_reported_at');
            $table->unsignedInteger('report_count')->default(1);
            $table->unsignedInteger('refused_count')->default(0);
            $table->dateTime('last_refused_at')->nullable();
            $table->timestamps();

            $table->index('install_code');
            $table->index('claimed_branch_id');
            $table->index('token_sha256');
        });

        Schema::create('local_licence_key_refusals', function (Blueprint $table) {
            $table->id();
            $table->string('local_licence_key_id', 26)->index();
            $table->string('install_code', 9);
            $table->string('install_id', 26)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->char('token_sha256', 64);
            $table->dateTime('refused_at');
        });

        Schema::create('cloud_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('branch_id', 26);
            $table->string('sync_key_id', 26)->nullable();
            $table->string('licence_id', 26)->nullable();
            $table->string('install_id', 26);
            $table->string('install_code', 9)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('till_company_id', 26);
            $table->string('till_branch_id', 26);
            $table->string('till_register_id', 26)->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('expected_rows')->default(0);
            $table->json('expected_row_counts')->nullable();
            $table->unsignedBigInteger('snapshot_change_log_seq')->default(0);
            $table->dateTime('first_sale_at')->nullable();
            $table->dateTime('last_sale_at')->nullable();
            $table->string('local_licence_id', 26)->nullable();
            $table->unsignedInteger('carried_over_days')->default(0);
            $table->json('id_mapping')->nullable();
            $table->unsignedBigInteger('acknowledged_seq')->default(0);
            $table->unsignedBigInteger('received_rows')->default(0);
            $table->dateTime('last_batch_at')->nullable();
            $table->unsignedInteger('complete_calls')->default(0);
            $table->json('missing')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'install_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_uploads');
        Schema::dropIfExists('local_licence_key_refusals');
        Schema::dropIfExists('local_licence_keys');
    }
};
