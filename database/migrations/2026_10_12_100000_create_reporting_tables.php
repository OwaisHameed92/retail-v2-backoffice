<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3.1: reporting tables (contract v1.4.1 DASHBOARD.md §4, built for MySQL 8 and SQLite).
 *
 * - `sales.trading_day` / `trading_hour`: the Europe/London date and hour of `completed_at` (a voided sale: of when it
 *   was voided), stamped by the applier at ingest (hand-written, not in till-schema.json).
 * - `rpt_*`: disposable summaries per (company, shop, trading day, till …), rebuilt a whole shop-day at a time.
 *   The primary key starts with company and shop and contains `trading_day`, so the tables can later be RANGE
 *   partitioned by month on it (docs/scaling.md).
 * - `rpt_dirty_days`: shop-days waiting for a rebuild; `token` changes on every mark so a rebuild that raced a
 *   newer push leaves the day queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('trading_day')->nullable();
            $table->unsignedTinyInteger('trading_hour')->nullable();
            $table->index(['company_id', 'branch_id', 'trading_day'], 'sales_company_branch_trading_day_index');
        });

        $this->create('rpt_sales_daily', [], function (Blueprint $table) {
            $this->money($table, ['gross', 'net', 'vat', 'refund_gross', 'refund_net', 'discount', 'promo', 'coupon']);
            $table->decimal('cost', 14, 4)->default(0);
            $this->money($table, ['container_deposits', 'takings', 'void_total']);
            $this->counts($table, ['txn_count', 'refund_count', 'void_count']);
        }, withDayIndex: true);

        $this->create('rpt_sales_hourly', ['hour'], function (Blueprint $table) {
            $table->unsignedTinyInteger('hour');
            $this->money($table, ['net', 'gross']);
            $this->counts($table, ['txn_count']);
        });

        $this->create('rpt_tender_daily', ['payment_type_id'], function (Blueprint $table) {
            $table->string('payment_type_id', 64);
            $table->string('payment_type_name')->default('');
            $this->money($table, ['amount', 'refunds']);
            $this->counts($table, ['count']);
        });

        $this->create('rpt_product_daily', ['product_id'], function (Blueprint $table) {
            $table->string('product_id', 64);
            $table->decimal('qty', 14, 4)->default(0);
            $table->decimal('refund_qty', 14, 4)->default(0);
            $this->money($table, ['gross', 'net', 'vat', 'refund_net', 'discount', 'promo']);
            $table->decimal('cost', 14, 4)->default(0);
            $table->string('last_name')->default('');
        });

        $this->create('rpt_vat_daily', ['vat_rate_id', 'percentage'], function (Blueprint $table) {
            $table->string('vat_rate_id', 64);
            $table->decimal('percentage', 9, 4);
            $table->string('code')->default('');
            $this->money($table, ['net', 'vat', 'gross']);
        });

        $this->create('rpt_staff_daily', ['user_id'], function (Blueprint $table) {
            $table->string('user_id', 64);
            $this->money($table, ['gross', 'net', 'refund_gross']);
            $this->counts($table, ['txn_count', 'refund_count', 'void_count']);
        });

        Schema::create('rpt_dirty_days', function (Blueprint $table) {
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->date('trading_day');
            $table->string('token', 26);
            $table->dateTime('marked_at');
            $table->primary(['company_id', 'branch_id', 'trading_day']);
            $table->index('marked_at');
        });
    }

    public function down(): void
    {
        foreach (['rpt_dirty_days', 'rpt_staff_daily', 'rpt_vat_daily', 'rpt_product_daily', 'rpt_tender_daily', 'rpt_sales_hourly', 'rpt_sales_daily'] as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_branch_trading_day_index');
            $table->dropColumn(['trading_day', 'trading_hour']);
        });
    }

    /**
     * @param  list<string>  $keys  key columns after company, shop, trading day and till
     */
    private function create(string $name, array $keys, Closure $columns, bool $withDayIndex = false): void
    {
        Schema::create($name, function (Blueprint $table) use ($name, $keys, $columns, $withDayIndex) {
            $table->string('company_id', 26);
            $table->string('branch_id', 26);
            $table->date('trading_day');
            $table->string('register_id', 26)->default('');
            $columns($table);
            $table->dateTime('rebuilt_at');
            $table->primary(['company_id', 'branch_id', 'trading_day', 'register_id', ...$keys]);
            $table->index(['company_id', 'trading_day'], $name.'_company_day_index');

            if ($withDayIndex) {
                // "Sales across all businesses" on the admin dashboard (DASHBOARD.md §3).
                $table->index('trading_day', $name.'_day_index');
            }
        });
    }

    /**
     * @param  list<string>  $names
     */
    private function money(Blueprint $table, array $names): void
    {
        foreach ($names as $name) {
            $table->decimal($name, 12, 2)->default(0);
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function counts(Blueprint $table, array $names): void
    {
        foreach ($names as $name) {
            $table->unsignedInteger($name)->default(0);
        }
    }
};
