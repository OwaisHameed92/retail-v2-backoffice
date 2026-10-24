<?php

use App\Domain\Billing\Enums\InvoiceLineKind;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\InvoiceLineBuilder;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\Plans\Enums\Feature;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit OSA-30: invoice lines carry an explicit kind, so the online store add-on line is recognised by its kind, not
 * by "no licence and no branch" (a setup fee or a typed line has neither and is not the online store).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    config(['online_store.enabled' => true]);
});

/** @return array<string, mixed> */
function iklLine(array $overrides = []): array
{
    return [
        'licence_id' => null, 'register_id' => null, 'branch_id' => null, 'plan_id' => null, 'description' => 'Something',
        'quantity' => '1.0000', 'unit_price' => '10.00', 'net' => '10.00', 'vat' => '2.00', 'gross' => '12.00',
        'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'position' => 1, ...$overrides,
    ];
}

test('the add-on is recognised by its kind; another line with no licence and no branch is not the online store', function () {
    expect(InvoiceLineBuilder::isOnlineStoreLine(iklLine(['kind' => InvoiceLineKind::OnlineStore->value])))->toBeTrue()
        ->and(InvoiceLineBuilder::isOnlineStoreLine(iklLine(['kind' => InvoiceLineKind::SetupFee->value])))->toBeFalse()
        ->and(InvoiceLineBuilder::isOnlineStoreLine(iklLine(['kind' => InvoiceLineKind::Manual->value])))->toBeFalse()
        ->and(InvoiceLineBuilder::isOnlineStoreLine(iklLine(['kind' => InvoiceLineKind::Licence->value, 'licence_id' => 'x'])))->toBeFalse()
        // A line array without a kind (built before it existed) keeps the old rule.
        ->and(InvoiceLineBuilder::isOnlineStoreLine(iklLine()))->toBeTrue()
        ->and(InvoiceLineBuilder::isOnlineStoreLine(iklLine(['licence_id' => 'x'])))->toBeFalse();
});

test('a period invoice stores the kind of each line: licences and the online store', function () {
    $company = $this->payingTenant('Khan Mini Mart', 2, 'KHN');

    foreach ($this->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value, Feature::CloudSync->value]])->save();
    }

    app(SetOnlineStoreAddOn::class)->handle($company, new OnlineStoreAddOnInput(true, null, StoreTemplate::FreshMarket));
    $invoice = $this->draftFor($company);

    expect($invoice->lines->pluck('kind')->all())->toBe(['licence', 'licence', 'onlineStore'])
        ->and($invoice->lines->filter(fn (InvoiceLine $l) => $l->isOnlineStore())->pluck('description')->all())
        ->toBe(['Online store · 1 Nov – 30 Nov 2026']);
});

test('older stored lines without a kind still read the online store line by the old rule', function () {
    $line = new InvoiceLine(['description' => 'Online store · 1 Nov – 30 Nov 2026', 'licence_id' => null, 'branch_id' => null]);
    $fee = new InvoiceLine(['description' => 'Setup fee · Khan Mini Mart', 'licence_id' => null, 'branch_id' => null]);
    $typed = new InvoiceLine(['description' => 'Online store · typed by hand', 'kind' => InvoiceLineKind::Manual->value]);

    expect($line->isOnlineStore())->toBeTrue()
        ->and($fee->isOnlineStore())->toBeFalse()
        ->and($typed->isOnlineStore())->toBeFalse();
});
