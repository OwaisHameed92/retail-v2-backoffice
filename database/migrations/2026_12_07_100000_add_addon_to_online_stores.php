<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online store OS-6: the add-on is switched on per business by an admin (SetOnlineStoreAddOn). Additive only:
 * `addon_on` (default false, so no existing row changes) and `addon_on_at` (when it was last switched on, UTC). The
 * price stays in the existing `monthly_price` (null = the default). How the add-on is billed is not wired yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->boolean('addon_on')->default(false)->after('order_email');
            $table->dateTime('addon_on_at')->nullable()->after('addon_on');
        });
    }

    public function down(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn(['addon_on', 'addon_on_at']);
        });
    }
};
