<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.5 (licence API). Device ids of other PCs are only kept as HMAC hashes; licence keys never.
     *
     * - licence_alerts: admin alerts raised by the till API (same key on two PCs, check-in from a PC that is not
     *   the bound one, an old key after a reissue). One open row per licence + type + PC, counted up.
     * - licence_devices: every PC that has used a licence key (hashed id), for support history.
     * - retired_licence_keys: hashes of keys replaced by "Reissue key", so the API can tell staff when the old
     *   PC still uses the old key. The API still answers licence.not_found for them.
     */
    public function up(): void
    {
        Schema::create('licence_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('licence_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            // HMAC of the PC's device id the alert is about: groups repeats of the same PC into one row.
            $table->char('fingerprint', 64);
            $table->json('details')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['licence_id', 'type', 'fingerprint', 'resolved_at']);
            $table->index(['company_id', 'resolved_at']);
        });

        Schema::create('licence_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('licence_id')->constrained()->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('device_name', 191)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_app_version', 50)->nullable();
            $table->string('last_outcome', 32);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamps();

            $table->unique(['licence_id', 'device_hash']);
        });

        Schema::create('retired_licence_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('licence_id')->constrained()->cascadeOnDelete();
            $table->char('key_hash', 64)->unique();
            $table->char('key_last4', 4);
            $table->char('bound_device_hash', 64)->nullable();
            $table->timestamp('retired_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retired_licence_keys');
        Schema::dropIfExists('licence_devices');
        Schema::dropIfExists('licence_alerts');
    }
};
