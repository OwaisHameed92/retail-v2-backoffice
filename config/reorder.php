<?php

/*
| Reorder suggestions (module 6.4). How far back sales are read, how long an order has to last and the fallbacks when
| a shop has no history. Explained in App\Domain\Purchasing\Reorder\ReorderCalculator.
*/

return [
    // Full weeks of sales read (ending yesterday). Recent weeks weigh more (8, 7, … 1).
    'history_weeks' => (int) env('REORDER_HISTORY_WEEKS', 8),

    // Days between two orders from the same supplier: an order must last until the next one arrives.
    'review_days' => (int) env('REORDER_REVIEW_DAYS', 7),

    // Supplier lead time when there is no delivery history and the supplier has no lead time of its own.
    'default_lead_days' => (int) env('REORDER_DEFAULT_LEAD_DAYS', 2),

    // Deliveries read to work out a supplier's lead time (median of the latest), and how many are needed.
    'lead_time_samples' => 10,
    'lead_time_min_samples' => 2,

    // Spare stock when the shop has no minimum level: this many days of sales.
    'safety_days' => 2,

    // Units sold in the history window below which the weekday pattern is blended towards flat.
    'weekday_pattern_units' => 28,

    // Seasonal uplift from last year's event: baseline days before the event, the fewest baseline units to trust it,
    // and the smallest and largest multiplier used.
    'event_baseline_days' => 28,
    'event_min_baseline_units' => 4,
    'event_factor_min' => '0.5',
    'event_factor_max' => '4',

    // Lines on one screen (narrow by shop, supplier or department beyond this).
    'max_lines' => 600,
];
