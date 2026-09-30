<?php

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Mail\Mailables\AdminTillRequestMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shops\Actions\SendShopRequest;
use App\Domain\Shops\Actions\UpdateBusinessDetails;
use App\Domain\Shops\Actions\UpdateShopDetails;
use App\Domain\Shops\Data\BusinessDetails;
use App\Domain\Shops\Data\ShopDetails;
use App\Domain\Shops\Data\ShopRequest;
use App\Domain\Shops\Enums\ShopRequestKind;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/** Module 4.7: the Shops and tills actions (shop and business edits reach the tills; "Ask for more tills"). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-10-25 10:00:00');
    $this->tenancy = app(CurrentCompany::class);
    $this->tenancy->set($this->company, CompanyRole::Owner);
    $this->owner = User::factory()->create(['name' => 'Asha Khan', 'email' => 'asha@kirkgate.test']);
    $this->licence = Licence::factory()->forRegister($this->sync->tills[TillFixtures::TILL_1])->paid(30)->create();
    Licence::factory()->forRegister($this->sync->tills[TillFixtures::BRADFORD_TILL])->paid(10)->create();
    Mail::fake();
});

test('a shop edit writes only the allowed fields, is audited and reaches that shop\'s till only', function () {
    $this->travel(1)->minutes();
    app(UpdateShopDetails::class)->handle($this->sync->bradford, new ShopDetails(name: 'Bradford Market St', address: '1 Market Street', phone: '01274 000111', receiptFooter: 'Thanks!'));

    $bradford = $this->sync->bradford->fresh();
    expect([$bradford->name, $bradford->code, $bradford->receipt_footer])->toBe(['Bradford Market St', 'BRD', 'Thanks!']);

    $changes = Pull::changes($this->sync->pull(0, bradford: true));
    expect(Pull::changes($this->sync->pull(0)))->toBe([])
        ->and($changes[0])->toMatchArray(['entity' => 'Branch', 'entityId' => TillFixtures::BRADFORD, 'op' => 'U'])
        ->and($changes[0]['payload'])->toMatchArray(['name' => 'Bradford Market St', 'address' => '1 Market Street', 'phone' => '01274 000111', 'code' => 'BRD'])
        ->and(ContractSchema::errors($changes[0]['payload'], 'schemas/entities/Branch.schema.json'))->toBe([]);

    $audit = AuditLog::query()->where('action', 'branch.updated')->sole();
    expect($audit->meta)->toBe(['via' => 'portal'])->and(array_keys($audit->after))->not->toContain('code');

    // Saving the same values again writes nothing and sends nothing new.
    app(UpdateShopDetails::class)->handle($this->sync->bradford->fresh(), new ShopDetails(name: 'Bradford Market St', address: '1 Market Street', phone: '01274 000111', receiptFooter: 'Thanks!'));
    expect(AuditLog::query()->where('action', 'branch.updated')->count())->toBe(1);
});

test('a business edit reaches every till as its Company row; admin-only fields are never written', function () {
    $this->company->forceFill(['notes' => 'Pays by DD', 'contact_name' => 'Owner'])->save();
    $this->travel(1)->minutes();
    app(UpdateBusinessDetails::class)->handle($this->company, new BusinessDetails(name: 'Kirkgate Stores Ltd', vatNumber: 'GB123456789', email: 'hello@kirkgate.test'));

    foreach ([false, true] as $bradford) {
        $change = Pull::changes($this->sync->pull(0, bradford: $bradford))[0];
        expect($change)->toMatchArray(['entity' => 'Company', 'entityId' => TillFixtures::COMPANY, 'op' => 'U'])
            ->and($change['payload'])->toMatchArray(['name' => 'Kirkgate Stores Ltd', 'vatNumber' => 'GB123456789', 'email' => 'hello@kirkgate.test'])
            ->and(ContractSchema::errors($change['payload'], 'schemas/entities/Company.schema.json'))->toBe([]);
    }

    $company = $this->company->fresh();
    expect([$company->notes, $company->contact_name])->toBe(['Pays by DD', 'Owner'])
        ->and(AuditLog::query()->where('action', 'company.updated')->sole()->meta)->toBe(['via' => 'portal']);
});

test('one-shop users may edit their own shop only and never the business', function () {
    $this->tenancy->set($this->company, CompanyRole::Manager, $this->sync->leeds->id);

    app(UpdateShopDetails::class)->handle($this->sync->leeds, new ShopDetails(name: 'Leeds Kirkgate'));
    expect($this->sync->leeds->fresh()->name)->toBe('Leeds Kirkgate');

    expect(fn () => app(UpdateShopDetails::class)->handle($this->sync->bradford, new ShopDetails(name: 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateBusinessDetails::class)->handle($this->company, new BusinessDetails(name: 'Hijack')))->toThrow(AuthorizationException::class)
        ->and($this->sync->bradford->fresh()->name)->not->toBe('Hijack');
});

test('asking for more tills raises one admin request on the shop\'s licence, audited and emailed to staff', function () {
    $this->sync->leeds->forceFill(['name' => 'Leeds'])->save();
    $request = new ShopRequest(ShopRequestKind::MoreTills, 2, $this->sync->leeds->id, message: 'Second counter', phone: '07700 900123');
    $alert = app(SendShopRequest::class)->handle($request, $this->owner);

    expect($alert->type)->toBe(LicenceAlertType::TillsRequested)
        ->and($alert->licence_id)->toBe($this->licence->id)
        ->and($alert->count)->toBe(1)
        ->and($alert->details['summary'])->toBe('2 more tills for Leeds (LDS) · asked by Asha Khan · “Second counter”')
        ->and(AuditLog::query()->where('action', 'shops.request_sent')->sole()->after)->toMatchArray(['kind' => 'moreTills', 'tills' => 2]);
    Mail::assertQueued(AdminTillRequestMail::class, fn (AdminTillRequestMail $mail) => $mail->data->what === '2 more tills for Leeds (LDS)'
        && $mail->data->phone === '07700 900123' && $mail->data->companyId === $this->company->id);

    // Asking again counts up the open request instead of adding another.
    $again = app(SendShopRequest::class)->handle($request, $this->owner);
    expect($again->id)->toBe($alert->id)->and($again->count)->toBe(2)
        ->and(LicenceAlert::withoutCompanyScope()->count())->toBe(1);

    // Once staff resolve it, a new ask opens a new request. Nothing was issued meanwhile.
    $alert->fresh()->forceFill(['resolved_at' => now()])->save();
    app(SendShopRequest::class)->handle($request, $this->owner);
    expect(LicenceAlert::withoutCompanyScope()->count())->toBe(2)
        ->and(Licence::withoutCompanyScope()->count())->toBe(2)
        ->and($this->sync->leeds->fresh()->max_registers)->toBe(1);
});

test('asking for another shop needs a name, is refused to one-shop users and needs a licence to hang on', function () {
    $alert = app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::NewShop, 1, newShopName: 'Harrogate'), $this->owner);
    expect($alert->details['summary'])->toStartWith('A new shop, Harrogate, with 1 till');

    expect(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::NewShop, 1), $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::MoreTills, 0, $this->sync->leeds->id), $this->owner))->toThrow(ValidationException::class);

    $this->tenancy->set($this->company, CompanyRole::Manager, $this->sync->leeds->id);
    expect(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::NewShop, 1, newShopName: 'York'), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::MoreTills, 1, $this->sync->bradford->id), $this->owner))->toThrow(AuthorizationException::class);

    $bare = Company::factory()->create();
    $this->tenancy->set($bare, CompanyRole::Owner);
    expect(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::NewShop, 1, newShopName: 'York'), $this->owner))->toThrow(ValidationException::class);
});

test('tenant isolation: another business\'s shop cannot be asked about or edited', function () {
    $other = Company::factory()->create();
    $otherShop = $this->tenancy->runAs($other, fn () => Branch::factory()->forCompany($other)->create(['name' => 'Theirs']));

    expect(fn () => app(SendShopRequest::class)->handle(new ShopRequest(ShopRequestKind::MoreTills, 1, $otherShop->id), $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateShopDetails::class)->handle($otherShop, new ShopDetails(name: 'Mine now')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateBusinessDetails::class)->handle($other, new BusinessDetails(name: 'Mine now')))->toThrow(AuthorizationException::class)
        ->and($otherShop->fresh()->name)->toBe('Theirs')
        ->and($other->fresh()->name)->not->toBe('Mine now');
});

test('the staff email is a registered template with a preview', function () {
    expect(EmailTemplates::keys())->toContain('admin-till-request')
        ->and(AdminTillRequestMail::sample()->render())->toContain('More tills requested');
});
