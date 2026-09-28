<?php

use App\Domain\Plans\Models\Plan;
use Illuminate\Support\Facades\DB;

/** Module 2.1: our old feature names become the till's 11 names in plans, licences and branch settings. */
it('renames stored feature values to the till names and drops ones the till has no feature for', function () {
    $plan = Plan::factory()->create();
    DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode(['stockControl', 'aiInsights', 'multiBranch', 'aiAssistant', 'purchasing', 'staff'])]);

    $migration = require database_path('migrations/2026_10_03_100000_create_id_map_and_sync_keys.php');
    (fn () => $this->renameFeatures())->call($migration);

    expect(json_decode((string) DB::table('plans')->where('id', $plan->id)->value('features'), true))
        ->toBe(['purchasing', 'multi_branch', 'assist', 'assist_invoice_scan', 'assist_questions'])
        ->and($plan->fresh()->featureValues())->toBe(['purchasing', 'multi_branch', 'assist', 'assist_invoice_scan', 'assist_questions']);
});
