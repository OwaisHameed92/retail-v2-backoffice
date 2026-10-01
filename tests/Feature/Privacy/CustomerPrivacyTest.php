<?php

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Privacy\Actions\AnonymiseCustomer;
use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\ConsentChannel;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Customers\CustomerFixtures as F;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 7.7: a customer's data export (subject access) and erasure (anonymise), owner only, audited, and one
 * business never reaching another's customers.
 */

beforeEach(function () {
    $this->travelTo('2026-10-24 12:00:00');
    $this->withoutVite();
    [$this->company] = TillFixtures::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->aisha = app(SaveCustomer::class)->handle($this->company, null, [
        'name' => 'Aisha Rahman', 'email' => 'aisha@example.co.uk', 'phone' => '07700 900123', 'card_no' => 'LC000123', 'address' => '14 Kirkgate, Leeds',
    ]);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    $this->bob = app(SaveCustomer::class)->handle($this->other, null, ['name' => 'Bob Other', 'email' => 'bob@example.co.uk']);
    $this->url = fn (string $what, ?string $id = null) => '/app/privacy/customers/'.($id ?? $this->aisha->id).'/'.$what;
});

function privacySale(string $companyId, string $branchId, string $customerId, string $id, string $receiptJson = '{}'): void
{
    DB::table('sales')->insert([
        'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customerId, 'receipt_number' => 'R-'.substr($id, -4),
        'type' => 'sale', 'status' => 'completed', 'total' => '12.00', 'vat_total' => '2.00', 'completed_at' => '2026-10-01 10:00:00', 'receipt_json' => $receiptJson,
    ]);
}

function privacyOrder(string $companyId, string $branchId, string $email): void
{
    DB::table('customer_orders')->insert([
        'id' => '01K5T0Q8C4000000000000ORD1', 'company_id' => $companyId, 'branch_id' => $branchId, 'reference' => 'CO-1', 'customer_name' => 'Aisha R',
        'customer_phone' => '', 'customer_email' => $email, 'status' => 'ready', 'goods_total' => '30.00', 'created_at' => '2026-10-02 09:00:00',
    ]);
}

test('guests are sent to log in; managers, accountants and staff get 403 on every privacy route', function () {
    $requests = [
        ['get', '/app/privacy'], ['put', '/app/privacy/settings'], ['post', '/app/privacy/retention/apply'],
        ['get', ($this->url)('export')], ['post', ($this->url)('anonymise')], ['post', '/app/privacy/requests/01K5T0Q8C4000000000000REQ1/till-done'],
    ];

    foreach ($requests as [$method, $url]) {
        $this->{$method}($url)->assertRedirect(route('login'));

        foreach ([CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff] as $role) {
            $this->actingAs(C::member($this->company, $role))->{$method}($url)->assertForbidden();
        }

        auth()->logout();
    }

    expect(CompanyRole::Owner->can('privacy.manage'))->toBeTrue()
        ->and(CompanyRole::Manager->can('privacy.manage'))->toBeFalse();
    $this->actingAs($this->owner)->get('/app/privacy')->assertOk()->assertInertia(fn ($page) => $page->component('app/privacy/index'));
});

test('owners download a ZIP of everything held about a customer; the request is recorded and audited without personal details', function () {
    F::ledger($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'charge', '12.00', 0, '2026-10-01 10:00:00');
    F::ledger($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'pointsEarn', '0.00', 12, '2026-10-01 10:00:00');
    F::consent($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'email', true, '2026-09-01 09:00:00');
    privacySale($this->company->id, TillFixtures::LEEDS, $this->aisha->id, '01K5T0Q8C4000000000000SAL1');
    privacyOrder($this->company->id, TillFixtures::LEEDS, 'AISHA@example.co.uk');
    F::ledger($this->other->id, $this->otherShop->id, $this->aisha->id, 'charge', '99.00', 0, '2026-10-01 10:00:00');

    $response = $this->actingAs($this->owner)->get(($this->url)('export'))->assertOk()->assertHeader('Content-Type', 'application/zip');
    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $data = json_decode((string) $zip->getFromName('customer-data.json'), true);
    $ledgerCsv = (string) $zip->getFromName('account-ledger.csv');
    $pdf = (string) $zip->getFromName('summary.pdf');
    $zip->close();

    expect($names)->toContain('README.txt', 'customer-data.json', 'summary.pdf', 'account-ledger.csv', 'consent-history.csv', 'sales.csv', 'customer-orders.csv', 'e-receipts.csv')
        ->and($data['customer'])->toMatchArray(['name' => 'Aisha Rahman', 'email' => 'aisha@example.co.uk', 'cardNo' => 'LC000123'])
        ->and($data['account'])->toBe(['balance' => '12.00', 'points' => 12])
        ->and($data['ledger'])->toHaveCount(2)
        ->and($data['loyalty']['earned'])->toBe(12)
        ->and($data['consent']['current'][0]['state'] ?? null)->not->toBeNull()
        ->and(collect($data['consent']['current'])->firstWhere('channel', 'email')['state'])->toBe('given')
        ->and($data['sales'][0]['receiptNumber'])->toBe('R-SAL1')
        ->and($data['customerOrders'][0]['reference'])->toBe('CO-1')
        ->and($ledgerCsv)->not->toContain('99.00')
        ->and(substr($pdf, 0, 4))->toBe('%PDF');

    $request = DataRequest::withoutCompanyScope()->sole();
    expect($request->type->value)->toBe('export')->and($request->status)->toBe(DataRequestStatus::Completed)
        ->and($request->company_id)->toBe($this->company->id)->and($request->requested_by_user_id)->toBe($this->owner->id);

    $audit = AuditLog::query()->where('action', 'customer.data_exported')->sole();
    expect($audit->subject_id)->toBe($this->aisha->id)->and(json_encode($audit->meta))->not->toContain('Aisha');
});

test('another business\'s customer is not found: no export, no erasure', function () {
    $this->actingAs($this->owner)->get(($this->url)('export', $this->bob->id))->assertNotFound();
    $this->actingAs($this->owner)->post(($this->url)('anonymise', $this->bob->id), ['confirm' => 'ANONYMISE'])->assertNotFound();

    $bob = Customer::withoutCompanyScope()->find($this->bob->id);
    expect($bob->name)->toBe('Bob Other')->and($bob->anonymised_at)->toBeNull()
        ->and(DataRequest::withoutCompanyScope()->count())->toBe(0);
});

test('anonymising clears the details here and for the tills, keeps the money records, blocks marketing and lists the till steps', function () {
    F::ledger($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'charge', '10.00', 0, '2026-10-01 10:00:00');
    F::ledger($this->company->id, TillFixtures::BRADFORD, $this->aisha->id, 'payment', '-10.00', 0, '2026-10-02 10:00:00');
    F::consent($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'email', true, '2026-09-01 09:00:00');
    privacySale($this->company->id, TillFixtures::LEEDS, $this->aisha->id, '01K5T0Q8C4000000000000SAL1', '{"customer":"Aisha Rahman"}');
    privacyOrder($this->company->id, TillFixtures::BRADFORD, 'aisha@example.co.uk');
    DB::table('email_logs')->insert(['id' => '01K5T0Q8C4000000000000EML1', 'company_id' => $this->company->id, 'to' => 'aisha@example.co.uk', 'mailable' => 'X', 'template' => 'customer-statement', 'subject' => 'Statement', 'status' => 'sent', 'meta' => '{"name":"Aisha"}', 'created_at' => now()]);
    $inCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
    expect($inCompany(fn () => MarketingConsent::allows($this->aisha->id, ConsentChannel::Email)))->toBeTrue();

    $this->actingAs($this->owner)->post(($this->url)('anonymise'), ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
    $this->actingAs($this->owner)->post(($this->url)('anonymise'), ['confirm' => 'ANONYMISE', 'note' => 'Asked by email'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $customer = Customer::withoutCompanyScope()->find($this->aisha->id);
    expect($customer->name)->toBe('Anonymised customer '.substr($this->aisha->id, -4))
        ->and([$customer->email, $customer->phone, $customer->address, $customer->card_no, $customer->notes])->toBe(['', '', '', '', ''])
        ->and($customer->is_active)->toBeFalse()
        ->and($customer->anonymised_at)->not->toBeNull()
        ->and($customer->row_version)->toBe(2)
        ->and($customer->hub_edited_at)->not->toBeNull()
        ->and(DB::table('customer_transactions')->where('customer_id', $this->aisha->id)->count())->toBe(2)
        ->and(DB::table('sales')->where('customer_id', $this->aisha->id)->count())->toBe(1)
        ->and($inCompany(fn () => MarketingConsent::allows($this->aisha->id, ConsentChannel::Email)))->toBeFalse();

    $request = DataRequest::withoutCompanyScope()->sole();
    expect($request->status)->toBe(DataRequestStatus::TillPending)
        ->and(array_column($request->till_steps, 'key'))->toBe(['customerOrders', 'receipts'])
        ->and($request->till_steps[0]['shops'])->toBe(['Bradford'])
        ->and($request->note)->toBe('Asked by email');

    $created = AuditLog::query()->where('action', 'customer.created')->where('subject_id', $this->aisha->id)->sole();
    expect(json_encode([$created->after, $created->meta]))->not->toContain('Aisha')
        ->and(AuditLog::query()->where('action', 'customer.anonymised')->exists())->toBeTrue()
        ->and(DB::table('email_logs')->where('id', '01K5T0Q8C4000000000000EML1')->value('to'))->toBe('[erased]');

    $this->actingAs($this->owner)->get('/app/customers/'.$this->aisha->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('privacy.anonymised', true)->where('privacy.requests.0.status', 'tillPending'));

    $this->actingAs($this->owner)->post('/app/privacy/requests/'.$request->id.'/till-done')->assertRedirect();
    expect($request->fresh()->status)->toBe(DataRequestStatus::Completed)->and($request->fresh()->till_done_at)->not->toBeNull();

    expect(fn () => app(AnonymiseCustomer::class)->handle($this->company, $this->aisha->id, null))->toThrow(ValidationException::class);
});

test('a customer whose account is not settled cannot be anonymised', function () {
    F::ledger($this->company->id, TillFixtures::LEEDS, $this->aisha->id, 'charge', '15.50', 0, '2026-10-01 10:00:00');

    $this->actingAs($this->owner)->post(($this->url)('anonymise'), ['confirm' => 'ANONYMISE'])->assertSessionHasErrors('customer');

    expect(Customer::withoutCompanyScope()->find($this->aisha->id)->anonymised_at)->toBeNull()
        ->and(DataRequest::withoutCompanyScope()->count())->toBe(0);
});

test('managers see no privacy tab on the customer page; owners do', function () {
    $this->actingAs(C::member($this->company, CompanyRole::Manager))->get('/app/customers/'.$this->aisha->id)
        ->assertInertia(fn ($page) => $page->where('privacy', null));
    $this->actingAs($this->owner)->get('/app/customers/'.$this->aisha->id)
        ->assertInertia(fn ($page) => $page->where('privacy.settled', true)->where('privacy.anonymised', false));
});
