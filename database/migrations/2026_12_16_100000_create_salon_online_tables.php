<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salon / Barber online booking and queue join (W3a, salon pack 2026-11-08 salon/README.md §4–§9). Portal-only tables,
 * never sent to or written by a till as rows. Additive only.
 *
 * - `salon_online_shops`: one per salon shop that called `sync/salon/*`. Its public address (`slug`, short, stable,
 *   never a sequence), the newest booking page the till published (`page`, JSON without the manage-link key; `page_hash`
 *   of the whole body for "unchanged"), the manage-link key (`manage_key`, encrypted, never logged) and its `keyId`,
 *   the last heartbeat (our clock in `last_heard_at`, the till's in `heartbeat_at`) and the last phone-queue push.
 * - `salon_queue_joins`: phone joins from the queue-join page; the id is the `joinId` the till uses as its ticket id.
 *   `client_key` (the form's own random key) makes a double tap one join. The till's answer is kept whole.
 * - `salon_online_request_sources`: where an online request came from (`?src=`), and the booking form's own key so a
 *   double tap is one request. One row per SalonOnlineBooking the portal made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_online_shops', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('slug', 60);
            $table->longText('page')->nullable();
            $table->char('page_hash', 64)->nullable();
            $table->dateTime('page_generated_at')->nullable();
            $table->dateTime('page_stored_at')->nullable();
            $table->string('manage_key_id', 100)->nullable();
            $table->text('manage_key')->nullable();
            $table->dateTime('last_heard_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->boolean('accepting')->nullable();
            $table->boolean('queue_join_open')->nullable();
            $table->unsignedInteger('pending_requests')->nullable();
            $table->unsignedInteger('offline_after_min')->nullable();
            $table->longText('queue')->nullable();
            $table->dateTime('queue_at')->nullable();
            $table->timestamps();

            $table->unique('branch_id', 'salon_online_shops_branch_unique');
            $table->unique('slug', 'salon_online_shops_slug_unique');
            $table->index('company_id', 'salon_online_shops_company_index');
        });

        Schema::create('salon_queue_joins', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('client_key', 64);
            $table->string('first_name', 60);
            $table->string('phone', 30)->default('');
            $table->text('service_ids');
            $table->string('stylist_user_id', 26)->nullable();
            $table->string('note', 200)->default('');
            $table->string('src', 30)->default('');
            $table->dateTime('joined_at');
            $table->dateTime('answered_at')->nullable();
            $table->string('answer_status', 20)->nullable();
            $table->text('answer')->nullable();
            $table->dateTime('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'client_key'], 'salon_queue_joins_branch_client_key_unique');
            $table->index(['branch_id', 'answered_at', 'dropped_at', 'joined_at'], 'salon_queue_joins_waiting_index');
            $table->index('company_id', 'salon_queue_joins_company_index');
        });

        Schema::create('salon_online_request_sources', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('client_key', 64);
            $table->string('src', 30)->default('');
            $table->dateTime('created_at')->nullable();

            $table->unique(['branch_id', 'client_key'], 'salon_online_request_sources_client_key_unique');
            $table->index(['company_id', 'src'], 'salon_online_request_sources_company_src_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_online_request_sources');
        Schema::dropIfExists('salon_queue_joins');
        Schema::dropIfExists('salon_online_shops');
    }
};
