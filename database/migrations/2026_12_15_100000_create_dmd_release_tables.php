<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4, the pharmacy dm+d release server (PORTAL-CHANGES-pharmacy.md item 12). Portal-only, not tenant data, never
 * synced. Additive only.
 *
 * - `dmd_releases`: one row per NHSBSA weekly zip we hold, written in one insert only after the zip is fully stored
 *   and checked, so the manifest (the newest row) never names a zip that is not there. `archive_file_name` is
 *   NHSBSA's own name, unchanged; `sha256` (lower-case hex) and `size_bytes` are of the stored file.
 * - `dmd_fetch_runs`: each run of the weekly fetch (published / unchanged / failed) with a redacted error, for the
 *   admin card. Never holds the TRUD key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dmd_releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('release_id', 150)->unique();
            $table->string('name', 150);
            $table->date('release_date');
            $table->string('week', 8);
            $table->string('archive_file_name', 160)->unique();
            $table->string('path', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['release_date', 'published_at'], 'dmd_releases_date_index');
        });

        Schema::create('dmd_fetch_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('outcome', 20);
            $table->string('release_id', 150)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['outcome', 'started_at'], 'dmd_fetch_runs_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dmd_fetch_runs');
        Schema::dropIfExists('dmd_releases');
    }
};
