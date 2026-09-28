<?php

use App\Domain\Plans\Enums\Feature;

it('lists exactly the till\'s 11 feature names (contract v1.3.3)', function () {
    expect(Feature::values())->toBe([
        'loyalty', 'promotions', 'purchasing', 'accounts', 'multi_branch', 'second_screen', 'label_printing',
        'cloud_sync', 'assist', 'assist_invoice_scan', 'assist_questions',
    ]);

    foreach (Feature::values() as $value) {
        expect($value)->toMatch('/^[a-z0-9]+([._-][a-z0-9]+)*$/');
    }
});

it('gives every feature a sentence-case label and a one-line description', function (Feature $feature) {
    expect($feature->label())->not->toBeEmpty()
        ->and($feature->label()[0])->toBe(strtoupper($feature->label()[0]))
        ->and($feature->description())->toEndWith('.')
        ->and(substr_count($feature->description(), "\n"))->toBe(0);
})->with(Feature::cases());

it('flags only the assist features as AI', function () {
    $ai = array_values(array_filter(Feature::cases(), fn (Feature $f) => $f->isAi()));

    expect($ai)->toBe([Feature::Assist, Feature::AssistInvoiceScan, Feature::AssistQuestions]);
});

it('normalises feature lists into enum order without duplicates or unknown values', function () {
    expect(Feature::normalise(['assist', 'second_screen', Feature::SecondScreen, 'bogus', 'stockControl', 42, null]))
        ->toBe([Feature::SecondScreen, Feature::Assist]);
});

it('shapes options for the form', function () {
    expect(Feature::options()[0])->toBe([
        'value' => 'loyalty',
        'label' => 'Loyalty',
        'description' => Feature::Loyalty->description(),
    ]);
});
