<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 2.7 (Till health). Additive only.
     *
     * - `licences`: what `licence/validate` reports that we did not keep yet: the `X-SSPOS-Contract` the till
     *   speaks and its optional `diagnostics` (only `pendingSyncRows`, `lastSyncError`, `databaseSizeMb`).
     * - `till_health`: one row per till (register), rebuilt by `till-health:refresh` every 5 minutes from the
     *   licences, registers and `sync_branch_status`: state, versions, clock skew, backlog and problem flags, all
     *   indexed so the admin list filters 1,000 businesses without scanning the source tables.
     * - `branch_health`: one row per shop: sync state, last contact/push/pull/error and till counts.
     */
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->string('last_contract_version', 16)->nullable()->after('last_app_version');
            $table->json('diagnostics')->nullable()->after('lock_reason');
            $table->timestamp('diagnostics_at')->nullable()->after('diagnostics');
        });

        Schema::create('till_health', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('register_id')->unique();
            $table->ulid('licence_id')->nullable();
            $table->string('state', 16);
            $table->boolean('is_sync_till')->default(false);
            $table->string('sync_state', 16)->default('notLinked');
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_validated_at')->nullable();
            $table->dateTime('last_push_at')->nullable();
            $table->dateTime('last_pull_at')->nullable();
            $table->string('app_version', 50)->nullable();
            $table->boolean('app_outdated')->default(false);
            $table->string('contract_version', 16)->nullable();
            $table->string('install_id', 64)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->boolean('clock_skewed')->default(false);
            $table->unsignedInteger('pending_sync_rows')->nullable();
            $table->unsignedInteger('pending_sync_rows_previous')->nullable();
            $table->dateTime('diagnostics_at')->nullable();
            $table->boolean('lock_locked')->nullable();
            $table->string('lock_reason', 40)->nullable();
            $table->unsignedTinyInteger('problem_count')->default(0);
            $table->dateTime('checked_at');
            $table->timestamps();

            $table->index(['state', 'last_seen_at']);
            $table->index(['company_id', 'state']);
            $table->index('app_outdated');
            $table->index('clock_skewed');
            $table->index('sync_state');
            $table->index('problem_count');
        });

        Schema::create('branch_health', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->ulid('branch_id')->unique();
            $table->string('state', 16);
            $table->string('sync_state', 16)->default('notLinked');
            $table->dateTime('last_contact_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->dateTime('last_push_at')->nullable();
            $table->dateTime('last_pull_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->unsignedSmallInteger('tills')->default(0);
            $table->unsignedSmallInteger('tills_online')->default(0);
            $table->unsignedSmallInteger('tills_offline')->default(0);
            $table->dateTime('checked_at');
            $table->timestamps();

            $table->index(['company_id', 'state']);
            $table->index('sync_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_health');
        Schema::dropIfExists('till_health');

        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn(['last_contract_version', 'diagnostics', 'diagnostics_at']);
        });
    }
};
