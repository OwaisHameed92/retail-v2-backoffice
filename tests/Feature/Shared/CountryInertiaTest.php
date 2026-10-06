<?php

use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the GB country profile with every Inertia page by default', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('country.code', 'GB')
        ->where('country.currency', 'GBP')
        ->where('country.currencySymbol', '£')
        ->where('country.displayDecimals', 2)
        ->where('country.numberLocale', 'en-GB')
        ->where('country.timezone', 'Europe/London')
        ->where('country.taxName', 'VAT')
        ->where('country.billingCollection', 'gocardless')
        ->where('country.features.vatReturn', true));
});

it('shares the GB profile on tenant pages', function () {
    $user = User::factory()->create();
    Company::factory()->withMember($user, CompanyRole::Staff)->create();

    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('country.code', 'GB')
        ->where('country.currencySymbol', '£'));
});

it('shares the Pakistan profile on a PK instance', function () {
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('country.code', 'PK')
        ->where('country.currency', 'PKR')
        ->where('country.currencySymbol', 'Rs')
        ->where('country.timezone', 'Asia/Karachi')
        ->where('country.taxName', 'GST')
        ->where('country.billingCollection', 'manual')
        ->where('country.features.vatReturn', false));
})->group('country-pk');
