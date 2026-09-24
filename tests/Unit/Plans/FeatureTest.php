<?php

use App\Domain\Plans\Enums\Feature;

it('lists the product features with camelCase values', function () {
    expect(Feature::values())->toBe([
        'stockControl', 'purchasing', 'cashOffice', 'accounts', 'staff',
        'customerOrders', 'newsDeliveries', 'multiBranch', 'aiAssistant', 'aiInsights',
    ]);

    foreach (Feature::values() as $value) {
        expect($value)->toMatch('/^[a-z][a-zA-Z]*$/');
    }
});

it('gives every feature a sentence-case label and a one-line description', function (Feature $feature) {
    expect($feature->label())->not->toBeEmpty()
        ->and($feature->label()[0])->toBe(strtoupper($feature->label()[0]))
        ->and($feature->description())->toEndWith('.')
        ->and(substr_count($feature->description(), "\n"))->toBe(0);
})->with(Feature::cases());

it('flags only the AI features as AI', function () {
    $ai = array_values(array_filter(Feature::cases(), fn (Feature $f) => $f->isAi()));

    expect($ai)->toBe([Feature::AiAssistant, Feature::AiInsights]);
});

it('normalises feature lists into enum order without duplicates or unknown values', function () {
    expect(Feature::normalise(['aiInsights', 'staff', Feature::Staff, 'bogus', 42, null]))
        ->toBe([Feature::Staff, Feature::AiInsights]);
});

it('shapes options for the form', function () {
    expect(Feature::options()[0])->toBe([
        'value' => 'stockControl',
        'label' => 'Stock control',
        'description' => Feature::StockControl->description(),
    ]);
});
