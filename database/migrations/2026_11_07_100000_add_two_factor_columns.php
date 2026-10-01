<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in (TOTP) for admins (required) and portal users (optional, or required by their company).
 * The secret is encrypted by the model cast; recovery codes are stored as keyed hashes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['admins', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                // Last accepted 30-second time step: a code is never accepted twice.
                $table->unsignedBigInteger('two_factor_last_step')->nullable();
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['admins', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });
    }
};
