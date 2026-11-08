<?php

/*
|--------------------------------------------------------------------------
| Till entity store: the Salon / Barber entities' overrides (W2), merged into definitions.php `entities`
|--------------------------------------------------------------------------
|
| Salon pack 2026-11-08 (docs/contracts/salon-barber-2026-11-08, the till's unreleased `feat/salon-barber` branch;
| docs/salon.md). Same keys as definitions.php `entities`. Every salon row carries its own branchId except the
| company-wide hub rows (commission plans, levels, service details, stamp and package rules, form templates), so a
| `parent` here only adds the relation and the parent-key index.
|
| Indexes are for the reports and screens W3 adds: by shop and time, by client (customer_id), by sale.
|
*/

use App\Domain\Salon\Concerns\SalonOnlineBookingRules;

return [
    // Hub-owned (the portal may write them; pulled like Product, to salon shops only: Salon\Support\SalonPull).
    'SalonCommissionTier' => ['parent' => ['SalonCommissionPlan', 'planId'], 'scope' => 'company'],
    'SalonCommissionCategoryRate' => ['parent' => ['SalonCommissionPlan', 'planId'], 'scope' => 'company'],
    'SalonCommissionFixedService' => ['parent' => ['SalonCommissionPlan', 'planId'], 'scope' => 'company'],
    // `rank` is a reserved word in MySQL 8.
    'SalonLevel' => ['columns' => ['rank' => 'level_rank']],
    'SalonServiceDetail' => ['parent' => ['Product', 'productId'], 'scope' => 'company'],
    'SalonServiceLevelPrice' => ['indexes' => [['company_id', 'product_id'], ['company_id', 'level_id']]],
    'SalonServiceAddOn' => ['indexes' => [['company_id', 'product_id']]],
    // One shop's online request (branchId set): portal and till each write their own members (SalonOnlineBookingRules,
    // Salon\Support\SalonBookingAnswers); pulled by that shop only.
    'SalonOnlineBooking' => [
        'indexes' => [['company_id', 'branch_id', 'status'], ['company_id', 'reference']],
        'traits' => [SalonOnlineBookingRules::class],
    ],

    // Branch-owned (pushed by the shop's tills, read-only on the portal). The append-only ones (SalonSaleLineStaff,
    // SalonLineStaffCorrection, SalonTip, SalonTipShare, SalonTipPayout, SalonStampEvent, SalonPackageUse: a correction
    // or refund is a new row) are historic from the start: a later change is kept out and recorded as a conflict.
    'SalonStaffProfile' => ['indexes' => [['company_id', 'user_id']]],
    'SalonStaffService' => ['indexes' => [['company_id', 'user_id']]],
    'SalonBlock' => ['indexes' => [['company_id', 'branch_id', 'starts_at']]],
    // `interval` is a reserved word in MySQL.
    'SalonRepeatRule' => ['columns' => ['interval' => 'repeat_interval']],
    'SalonAppointment' => [
        'indexes' => [['company_id', 'branch_id', 'starts_at'], ['company_id', 'customer_id'], ['online_booking_id']],
    ],
    'SalonAppointmentService' => [
        'parent' => ['SalonAppointment', 'appointmentId'],
        'scope' => 'branch',
        'indexes' => [['company_id', 'stylist_user_id', 'starts_at']],
    ],
    'SalonWaitlistEntry' => ['indexes' => [['company_id', 'branch_id', 'earliest_date']]],
    'SalonQueueEntry' => ['indexes' => [['company_id', 'branch_id', 'trading_date', 'queue_order']]],
    'SalonQueueEntryService' => ['parent' => ['SalonQueueEntry', 'queueEntryId'], 'scope' => 'branch'],
    'SalonSaleLineStaff' => [
        'indexes' => [['company_id', 'branch_id', 'sold_at'], ['company_id', 'user_id', 'sold_at'], ['sale_id'], ['sale_line_id']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonLineStaffCorrection' => ['indexes' => [['sale_id']], 'immutable' => ['always' => true, 'mutable' => []]],
    'SalonTip' => [
        'indexes' => [['company_id', 'branch_id', 'taken_at'], ['sale_id'], ['shift_id']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonTipShare' => [
        'parent' => ['SalonTip', 'tipId'],
        'scope' => 'branch',
        'indexes' => [['company_id', 'user_id', 'taken_at']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonTipPayout' => [
        'indexes' => [['company_id', 'branch_id', 'paid_at'], ['shift_id']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonRenterAgreement' => ['indexes' => [['company_id', 'user_id']]],
    'SalonRenterStatement' => ['indexes' => [['company_id', 'branch_id', 'period_from'], ['company_id', 'user_id']]],
    'SalonClientCard' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonTechnicalRecord' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonClientPhoto' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonConsultationAnswer' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonPatchTest' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonStampCard' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonStampEvent' => [
        'parent' => ['SalonStampCard', 'cardId'],
        'scope' => 'branch',
        'indexes' => [['company_id', 'branch_id', 'at']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonPackageHolding' => ['indexes' => [['company_id', 'customer_id']]],
    'SalonPackageUse' => [
        'parent' => ['SalonPackageHolding', 'holdingId'],
        'scope' => 'branch',
        'indexes' => [['company_id', 'branch_id', 'at']],
        'immutable' => ['always' => true, 'mutable' => []],
    ],
    'SalonMessageOutboxItem' => ['indexes' => [['company_id', 'branch_id', 'due_at']]],
];
