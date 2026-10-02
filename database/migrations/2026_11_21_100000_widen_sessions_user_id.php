<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database session driver writes the signed-in user's id, and admins have ULID ids: an integer column made
 * MySQL refuse every admin session write (SQLite accepted it). Widen it to a string that holds both kinds of id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('user_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible safely: ULIDs do not fit back into an integer column.
    }
};
