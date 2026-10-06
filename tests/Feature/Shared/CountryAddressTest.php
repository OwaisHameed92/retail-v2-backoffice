<?php

use App\Domain\Ai\Actions\AskPortalAssistant;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadDuplicates;
use App\Domain\Leads\Support\PhoneDigits;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Requests\Admin\Leads\LeadRequest;
use App\Http\Requests\Admin\TenantRules;
use App\Http\Requests\Api\StoreTrialRequest;
use App\Http\Requests\App\Setup\SupplierRequest;
use App\Http\Requests\App\Shops\ShopRequestRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P4: postcode, town and phone rules per country profile (ContactRules). GB golden tests pin today's UK
 * rules and messages byte for byte; PK tests prove the optional 5-digit postal code, the city with an address, the
 * Pakistani phone numbers and their duplicate normalisation.
 */

uses(TenantTestHelpers::class);

/** A Pakistan instance: COUNTRY=PK and the profile singleton rebuilt. */
function asPakistanAddressInstance(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
}

/**
 * The first error per field when `$data` is checked against a form's rules and messages.
 *
 * @param  array<string, mixed>  $rules
 * @param  array<string, string>  $messages
 * @param  array<string, mixed>  $data
 * @return array<string, string>
 */
function addressErrors(array $rules, array $messages, array $data): array
{
    return array_map(fn (array $list) => $list[0], Validator::make($data, $rules, $messages)->errors()->toArray());
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addressTrialBody(array $overrides = []): array
{
    return array_replace([
        'businessName' => 'Khan Mini Mart', 'contactName' => 'Ali Khan', 'email' => 'ali@khanmart.test', 'phone' => '07700 900123',
        'town' => 'Leeds', 'postcode' => 'ls16bx', 'shopsCount' => 1, 'tillsCount' => 1, 'businessType' => 'ConvenienceOffLicence',
        'website' => '',
    ], $overrides);
}

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['services.turnstile.secret' => '', 'sspos.public_form_origins' => ['https://switchandsave.co.uk']]);
});

// GB golden tests: the UK rules and messages are exactly as before.

it('keeps the GB tenant and branch postcode, town and phone rules and messages', function () {
    $company = TenantRules::company();
    $branch = TenantRules::branch('branch_');

    expect($company['postcode'])->toBe(['nullable', 'string', 'max:10', 'regex:'.TenantRules::POSTCODE_PATTERN])
        ->and(TenantRules::POSTCODE_PATTERN)->toBe('/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/')
        ->and($company['town'])->toBe(['nullable', 'string', 'max:80'])
        ->and($company['phone'])->toBe(['nullable', 'string', 'regex:'.TenantRules::PHONE_PATTERN])
        ->and(TenantRules::PHONE_PATTERN)->toBe('/^[0-9+()\s-]{7,20}$/')
        ->and($branch['branch_postcode'])->toBe(['nullable', 'string', 'max:10', 'regex:'.TenantRules::POSTCODE_PATTERN])
        ->and($branch['branch_town'])->toBe(['nullable', 'string', 'max:80'])
        ->and($branch['branch_phone'])->toBe(['nullable', 'string', 'regex:'.TenantRules::PHONE_PATTERN])
        ->and(TenantRules::messages('branch_'))->toMatchArray([
            'phone.regex' => 'Enter a phone number like 0113 496 0000.',
            'postcode.regex' => 'Enter a UK postcode like LS1 6AB.',
            'branch_postcode.regex' => 'Enter a UK postcode like LS1 6AB.',
            'branch_phone.regex' => 'Enter a phone number like 0113 496 0000.',
        ])
        ->and(TenantRules::messages('branch_'))->not->toHaveKey('town.required_with')
        ->and(TenantRules::messages('branch_'))->not->toHaveKey('branch_town.required_with');
});

it('accepts and refuses UK postcodes on the GB tenant form with the same message', function () {
    $this->actingAs($this->admin(), 'admin');
    $company = $this->tenant();

    foreach (['LS1 6AB', 'ls16ab', 'SW1A 1AA', 'M1 1AE', 'b33 8th'] as $postcode) {
        $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'postcode' => $postcode])->assertSessionHasNoErrors();
    }

    foreach (['54000', 'LS1', 'LS1 6A', 'ABC DEF'] as $postcode) {
        $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'postcode' => $postcode])
            ->assertSessionHasErrors(['postcode' => 'Enter a UK postcode like LS1 6AB.']);
    }

    // No town needed with an address on GB; phones as before.
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'address' => '1 High Street', 'postcode' => 'LS1 6AB', 'phone' => '+44 113 496 0000'])
        ->assertSessionHasNoErrors();
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'phone' => 'call me'])
        ->assertSessionHasErrors(['phone' => 'Enter a phone number like 0113 496 0000.']);

    expect($company->fresh()?->postcode)->toBe('LS1 6AB');
});

it('keeps the GB lead form postcode, town and phone rules and messages', function () {
    $request = new LeadRequest;
    $rules = $request->rules();

    expect($rules['phone'])->toBe(['nullable', 'required_without:email', 'string', 'regex:'.TenantRules::PHONE_PATTERN])
        ->and($rules['town'])->toBe(['nullable', 'string', 'max:80'])
        ->and($rules['postcode'])->toBe(['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9 ]{2,10}$/'])
        ->and($request->messages())->toMatchArray([
            'phone.regex' => 'Enter a phone number like 07700 900123.',
            'postcode.regex' => 'Enter a postcode like LS1 6BX.',
        ])
        ->and($request->messages())->not->toHaveKey('town.required_with')
        ->and(addressErrors($rules, $request->messages(), ['phone' => 'phone me', 'postcode' => 'LS1-6BX']))->toMatchArray([
            'phone' => 'Enter a phone number like 07700 900123.',
            'postcode' => 'Enter a postcode like LS1 6BX.',
        ])
        ->and(addressErrors($rules, $request->messages(), ['phone' => '07700 900123', 'postcode' => 'ls1 6bx']))->not->toHaveKeys(['phone', 'postcode', 'town']);
});

it('keeps the GB public trial API rules and messages the UK website relies on', function () {
    $request = new StoreTrialRequest;
    $rules = $request->rules();

    expect($rules['phone'])->toBe(['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{7,}$/'])
        ->and($rules['town'])->toBe(['required', 'string', 'max:80'])
        ->and($rules['postcode'])->toBe(['required', 'string', 'max:10'])
        ->and($request->messages())->toMatchArray([
            'phone.required' => 'Enter a phone number so we can call you.',
            'phone.regex' => 'Enter a valid phone number, like 07700 900123.',
            'town.required' => 'Enter the town your shop is in.',
            'postcode.required' => 'Enter your shop’s postcode.',
        ])
        ->and($request->messages())->not->toHaveKey('postcode.regex');

    $headers = ['Origin' => 'https://switchandsave.co.uk'];
    $this->postJson('/api/v1/public/trial-requests', addressTrialBody(['postcode' => '', 'phone' => 'call']), $headers)->assertStatus(400)
        ->assertJsonPath('details.fields.postcode.0', 'Enter your shop’s postcode.')
        ->assertJsonPath('details.fields.phone.0', 'Enter a valid phone number, like 07700 900123.');
    $this->postJson('/api/v1/public/trial-requests', addressTrialBody(), $headers)->assertCreated();

    expect(Lead::query()->sole()->postcode)->toBe('LS1 6BX');
});

it('keeps the GB supplier and shop request rules', function () {
    $supplier = new SupplierRequest;
    $shopRequest = new ShopRequestRequest;

    expect($supplier->rules()['town'])->toBe(['nullable', 'string', 'max:120'])
        ->and($supplier->rules()['postcode'])->toBe(['nullable', 'string', 'max:12'])
        ->and($supplier->messages())->not->toHaveKeys(['postcode.regex', 'town.required_with'])
        ->and($shopRequest->rules()['phone'])->toBe(['nullable', 'string', 'regex:'.TenantRules::PHONE_PATTERN])
        ->and($shopRequest->messages()['phone.regex'])->toBe('Enter a phone number like 0113 496 0000.');
});

it('compares UK phone numbers for duplicates as before', function (string $typed) {
    expect(PhoneDigits::from($typed))->toBe('07700900123');
})->with(['07700 900123', '+44 7700 900123', '+44 (0)7700 900-123', '0044 7700 900123', '447700900123']);

it('keeps other GB phone digits untouched', function () {
    expect(PhoneDigits::from('+92 300 1234567'))->toBe('923001234567')
        ->and(PhoneDigits::from('0113 496 0000'))->toBe('01134960000')
        ->and(PhoneDigits::from('12345'))->toBeNull()
        ->and(PhoneDigits::from(null))->toBeNull();
});

it('scrubs UK phone numbers from assistant questions as before', function () {
    expect(AskPortalAssistant::scrub('Call 07700 900123 or +44 7700 900123 about £1,234.50'))
        ->toBe('Call [phone removed] or [phone removed] about £1,234.50');
});

// Pakistan.

it('takes the postcode, town and phone rules from the PK profile', function () {
    asPakistanAddressInstance();
    $company = TenantRules::company();
    $branch = TenantRules::branch('branch_');

    expect($company['postcode'])->toBe(['nullable', 'string', 'max:10', 'regex:/^\d{5}$/'])
        ->and($company['town'])->toBe(['nullable', 'string', 'max:80', 'required_with:address,postcode'])
        ->and($company['phone'])->toBe(['nullable', 'string', 'regex:'.app(Country::class)->phone()['pattern']])
        ->and($branch['branch_town'])->toBe(['nullable', 'string', 'max:80', 'required_with:branch_address,branch_postcode'])
        ->and(TenantRules::messages('branch_'))->toMatchArray([
            'phone.regex' => 'Enter a phone number like 0300 1234567.',
            'postcode.regex' => 'Enter a postal code like 54000.',
            'branch_postcode.regex' => 'Enter a postal code like 54000.',
            'branch_phone.regex' => 'Enter a phone number like 0300 1234567.',
            'town.required_with' => 'Enter the city for this address.',
            'branch_town.required_with' => 'Enter the city for this address.',
        ]);
})->group('country-pk');

it('accepts an optional 5-digit postal code and Pakistani phones on the PK tenant form', function () {
    asPakistanAddressInstance();
    $this->actingAs($this->admin(), 'admin');
    $company = $this->tenant();

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'postcode' => ''])->assertSessionHasNoErrors();

    foreach (['0300 1234567', '+92 300 1234567', '0092 300 1234567', '03001234567', '0300-1234567', '042 35761234'] as $phone) {
        $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'phone' => $phone])->assertSessionHasNoErrors();
    }

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'postcode' => 'LS1 6AB', 'phone' => '+44 7700 900123'])
        ->assertSessionHasErrors(['postcode' => 'Enter a postal code like 54000.', 'phone' => 'Enter a phone number like 0300 1234567.']);
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'postcode' => '5400'])
        ->assertSessionHasErrors(['postcode' => 'Enter a postal code like 54000.']);

    // The city is needed as soon as an address or postal code is entered.
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'address' => '12 Mall Road', 'postcode' => '54000'])
        ->assertSessionHasErrors(['town' => 'Enter the city for this address.']);
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'address' => '12 Mall Road', 'town' => 'Lahore', 'postcode' => '54000'])
        ->assertSessionHasNoErrors();

    expect($company->fresh()?->postcode)->toBe('54000')
        ->and($company->fresh()?->town)->toBe('Lahore');

    $this->get("/admin/tenants/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('country.address.postcodeLabel', 'Postal code')
        ->where('country.address.postcodeRequired', false)
        ->where('country.phoneExample', '0300 1234567'));
})->group('country-pk');

it('needs the city with a shop address on the PK shop details form', function () {
    asPakistanAddressInstance();
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    $branch = Branch::withoutCompanyScope()->where('company_id', $company->id)->firstOrFail();
    $form = ['name' => 'Mall Road', 'address' => '12 Mall Road', 'town' => '', 'postcode' => '54000', 'phone' => '+92 42 35761234', 'vat_number' => '', 'receipt_footer' => ''];

    $this->actingAs($owner)->put("/app/shops/{$branch->id}", $form)->assertSessionHasErrors(['town' => 'Enter the city for this address.']);
    $this->actingAs($owner)->put("/app/shops/{$branch->id}", ['town' => 'Lahore'] + $form)->assertSessionHasNoErrors();

    expect(Branch::withoutCompanyScope()->findOrFail($branch->id)->postcode)->toBe('54000');
})->group('country-pk');

it('takes the lead form, supplier and shop request rules from the PK profile', function () {
    asPakistanAddressInstance();
    $lead = new LeadRequest;
    $supplier = new SupplierRequest;
    $shopRequest = new ShopRequestRequest;

    expect(addressErrors($lead->rules(), $lead->messages(), ['phone' => '+44 7700 900123', 'postcode' => 'LS1 6BX']))->toMatchArray([
        'phone' => 'Enter a phone number like 0300 1234567.',
        'postcode' => 'Enter a postal code like 54000.',
        'town' => 'Enter the city for this address.',
    ])
        ->and(addressErrors($lead->rules(), $lead->messages(), ['phone' => '+92 300 1234567', 'postcode' => '54000', 'town' => 'Lahore']))->not->toHaveKeys(['phone', 'postcode', 'town'])
        ->and(addressErrors($lead->rules(), $lead->messages(), ['phone' => '0300 1234567']))->not->toHaveKeys(['phone', 'postcode', 'town'])
        ->and(addressErrors($supplier->rules(), $supplier->messages(), ['address_line1' => '5 Jail Road', 'postcode' => 'LS1 6AB']))->toMatchArray([
            'postcode' => 'Enter a postal code like 54000.',
            'town' => 'Enter the city for this address.',
        ])
        ->and(addressErrors($supplier->rules(), $supplier->messages(), ['address_line1' => '5 Jail Road', 'town' => 'Lahore', 'postcode' => '54000']))->not->toHaveKeys(['postcode', 'town'])
        ->and(addressErrors($shopRequest->rules(), $shopRequest->messages(), ['phone' => '12']))->toMatchArray(['phone' => 'Enter a phone number like 0300 1234567.']);
})->group('country-pk');

it('accepts a PK trial request without a postal code and with a Pakistani mobile', function () {
    asPakistanAddressInstance();
    $headers = ['Origin' => 'https://switchandsave.co.uk'];
    $body = addressTrialBody(['phone' => '+92 300 1234567', 'town' => 'Lahore', 'postcode' => null]);

    $this->postJson('/api/v1/public/trial-requests', addressTrialBody(['phone' => '+44 7700 900123', 'postcode' => 'LS1 6BX']), $headers)->assertStatus(400)
        ->assertJsonPath('details.fields.postcode.0', 'Enter a postal code like 54000.')
        ->assertJsonPath('details.fields.phone.0', 'Enter a valid phone number, like 0300 1234567.');
    $this->postJson('/api/v1/public/trial-requests', $body, $headers)->assertCreated();

    $lead = Lead::query()->sole();
    expect($lead->postcode)->toBeNull()
        ->and($lead->phone_digits)->toBe('03001234567');
})->group('country-pk');

it('compares Pakistani phone numbers for duplicates whatever the prefix', function (string $typed) {
    asPakistanAddressInstance();

    expect(PhoneDigits::from($typed))->toBe('03001234567');
})->with(['0300 1234567', '+92 300 1234567', '0092 300 1234567', '923001234567', '+92 (0)300 1234567', '0300-1234567'])->group('country-pk');

it('flags a PK lead as a duplicate when the same mobile is typed another way', function () {
    asPakistanAddressInstance();
    $first = Lead::factory()->create(['phone' => '+92 300 1234567', 'email' => null]);
    $second = Lead::factory()->create(['phone' => '0300-1234567', 'email' => null]);

    $duplicates = LeadDuplicates::for($second);

    expect($first->phone_digits)->toBe('03001234567')
        ->and($duplicates)->toHaveCount(1)
        ->and($duplicates[0]->matchedOn)->toBe(['phone']);
})->group('country-pk');

it('scrubs Pakistani phone numbers from assistant questions', function () {
    asPakistanAddressInstance();

    expect(AskPortalAssistant::scrub('Call 0300 1234567 or +92 300 1234567 about Rs 1,250'))
        ->toBe('Call [phone removed] or [phone removed] about Rs 1,250');
})->group('country-pk');
