<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7.8 (owner alerts): each portal user's alert choices per business, which alert emails went to whom (the
 * 6-hour de-duplication and the "resolved" email) and the in-app notifications of the top-bar bell. Portal-only
 * tables (never synced with the till).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id');
            // {alertType: off|digest|immediate}; a missing type takes the role's default.
            $table->json('deliveries')->nullable();
            // Shops the user wants alerts for; null = every shop they can see.
            $table->json('branch_ids')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'alert_preferences_company_user_unique');
            $table->index('user_id', 'alert_preferences_user_index');
        });

        Schema::create('alert_dispatches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->string('alert_type', 32);
            // What the email was about: "<licence alert type>|<licence id>" for urgent alerts, "digest|<London day>".
            $table->string('subject_key', 120);
            $table->string('state', 16); // open | resolved | sent
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'subject_key'], 'alert_dispatches_subject_unique');
            $table->index(['state', 'alert_type'], 'alert_dispatches_state_index');
        });

        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->string('alert_type', 32);
            $table->string('tone', 16);
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at'], 'alert_notifications_user_index');
            $table->index('created_at', 'alert_notifications_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
        Schema::dropIfExists('alert_dispatches');
        Schema::dropIfExists('alert_preferences');
    }
};
