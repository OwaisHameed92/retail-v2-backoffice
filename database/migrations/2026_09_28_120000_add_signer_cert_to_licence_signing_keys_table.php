<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contract v1.3.1 §17.17: the owner's signer certificate for the key (SSPOSCERT1…, ~300 chars). Not
        // secret; it rides unchanged in every token the key signs.
        Schema::table('licence_signing_keys', function (Blueprint $table) {
            $table->text('signer_cert')->nullable()->after('secret_key');
        });

        // Kids are now "k" + 8 hex of SHA-256(public key) (§17.2). Re-key the dev-only lk<year>-<nn> rows;
        // there is no production data (owner, 2026-09-28).
        foreach (DB::table('licence_signing_keys')->get(['id', 'public_key']) as $row) {
            $public = base64_decode(strtr((string) $row->public_key, '-_', '+/'), true);

            if (is_string($public) && strlen($public) === 32) {
                DB::table('licence_signing_keys')->where('id', $row->id)
                    ->update(['kid' => 'k'.substr(hash('sha256', $public), 0, 8)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('licence_signing_keys', function (Blueprint $table) {
            $table->dropColumn('signer_cert');
        });
    }
};
