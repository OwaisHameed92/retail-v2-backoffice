<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ed25519 keys that sign licence tokens (module 1.4). Not tenant data: no company_id.
        Schema::create('licence_signing_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kid', 32)->unique();
            // 32-byte public key, base64url without padding (43 chars): the JWK "x".
            $table->string('public_key', 64);
            // Laravel-encrypted base64url secret key. Null once the key is retired: it never signs again.
            $table->text('secret_key')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('retired_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_signing_keys');
    }
};
