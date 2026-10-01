<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ANSWERS-2026-10-01 §1, contract §10.7: `User.pinHash` goes in a pull only to set or change a PIN. The pull
 * version at which the current hash first went out (`pin_hash_version`) and the hash it was stamped for
 * (`pin_hash_versioned`) let a pull send it only to tills that have not had it yet (HubVersions, PullPayload).
 * Rows already holding a hash in the till's format keep sending it from their current version; older Identity v3
 * hashes are left unversioned ("PIN needs resetting", never sent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('till_users', function (Blueprint $table) {
            $table->unsignedBigInteger('pin_hash_version')->nullable();
            $table->string('pin_hash_versioned', 255)->nullable();
        });

        DB::table('till_users')->where('pin_hash', 'like', 'pbkdf2$%')->whereNotNull('hub_version')->update([
            'pin_hash_version' => DB::raw('hub_version'),
            'pin_hash_versioned' => DB::raw('pin_hash'),
        ]);
    }

    public function down(): void
    {
        Schema::table('till_users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash_version', 'pin_hash_versioned']);
        });
    }
};
