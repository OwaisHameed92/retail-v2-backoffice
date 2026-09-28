<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.5 rework for contract v1.3.1 (§17.15): what each till reports through `licence/activate` and
     * `licence/validate`. `licences.device_id` now holds the till's `installId` (the PC), unchanged column.
     *
     * - install_code, existing_ids (the till's own company/branch/register ULIDs), os (name/version/architecture);
     * - till clock: skew in seconds (tillClockUtc − portal time), the till's clock watermark;
     * - lock state the till reported, last successful validate;
     * - the last token we sent (SHA-256, kid, fingerprint of its claims) so validate sends a new one only on change;
     * - licence_devices.released_at: this install was released (admin Release, devices/deactivate, reissued key),
     *   so its next validate gets `released`.
     */
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->string('install_code', 9)->nullable()->after('device_name');
            $table->json('existing_ids')->nullable()->after('install_code');
            $table->json('os')->nullable()->after('last_app_version');
            $table->integer('till_clock_skew_seconds')->nullable()->after('os');
            $table->timestamp('clock_watermark_at')->nullable()->after('till_clock_skew_seconds');
            $table->timestamp('last_validated_at')->nullable()->after('clock_watermark_at');
            $table->boolean('lock_locked')->nullable()->after('last_validated_at');
            $table->string('lock_reason', 40)->nullable()->after('lock_locked');
            $table->char('token_sha256', 64)->nullable()->after('lock_reason');
            $table->string('token_kid', 64)->nullable()->after('token_sha256');
            $table->char('token_fingerprint', 64)->nullable()->after('token_kid');
        });

        Schema::table('licence_devices', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('licence_devices', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });

        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn([
                'install_code', 'existing_ids', 'os', 'till_clock_skew_seconds', 'clock_watermark_at',
                'last_validated_at', 'lock_locked', 'lock_reason', 'token_sha256', 'token_kid', 'token_fingerprint',
            ]);
        });
    }
};
