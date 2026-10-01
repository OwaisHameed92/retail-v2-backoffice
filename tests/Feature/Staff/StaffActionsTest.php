<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Staff\Actions\AssignStaffFob;
use App\Domain\Staff\Actions\DeleteStaffMember;
use App\Domain\Staff\Actions\SaveRolePermissions;
use App\Domain\Staff\Actions\SaveStaffMember;
use App\Domain\Staff\Actions\SetStaffPin;
use App\Domain\Staff\Models\StaffBranch;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillRolePermission;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Staff\StaffFixtures as Staff;
use Tests\Feature\Sync\SyncApiFixtures;

/** Module 4.5: the till staff Actions (PIN hash, fob, last Owner, shops) and the role permission editor. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    Staff::roles($this->company);
    $this->owner = Staff::member($this->company, 'Imran Khan', '7391', Staff::OWNER);
    $this->errors = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

test('the PIN hash is the till\'s pbkdf2 format, salted, and verifies only the right PIN', function () {
    $hasher = new TillPinHasher;
    $hash = $hasher->hash('4821');

    expect($hash)->toMatch('/^pbkdf2\$100000\$[A-Za-z0-9+\/]{22}==\$[A-Za-z0-9+\/]{43}=$/')
        ->and($hasher->verify('4821', $hash))->toBeTrue()
        ->and($hasher->verify('4822', $hash))->toBeFalse()
        ->and($hasher->hash('4821'))->not->toBe($hash)
        ->and($hasher->verify('4821', ''))->toBeFalse()
        ->and($hasher->verify('4821', 'not-a-hash'))->toBeFalse();
});

test('adding a member keeps only the PIN hash, fills the till fields and records the shops', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821', Staff::CASHIER, ['branch_ids' => [$this->sync->leeds->id]]);
    $row = TillUser::withoutCompanyScope()->findOrFail($member->id);

    expect($row->pin_hash)->not->toBe('4821')->not->toContain('4821')
        ->and((new TillPinHasher)->verify('4821', $row->pin_hash))->toBeTrue()
        ->and($row->rfid)->toBe('')
        ->and($row->company_id)->toBe($this->company->id)
        ->and($row->rate_per_hour)->toBe('11.44')
        ->and($row->preferred_culture)->toBe('en-GB')
        ->and($row->is_active)->toBeTrue()
        ->and($row->row_version)->toBe(1)
        ->and(StaffBranch::withoutCompanyScope()->where('till_user_id', $row->id)->pluck('branch_id')->all())->toBe([$this->sync->leeds->id]);

    $audit = AuditLog::query()->where('action', 'staff.created')->latest('id')->firstOrFail();
    expect(json_encode($audit->toArray()))->not->toContain('4821')->not->toContain($row->pin_hash);
});

test('a PIN must be 4 to 8 digits, hard to guess and not a colleague\'s', function (string $pin, string $message) {
    $errors = ($this->errors)(fn () => Staff::member($this->company, 'Aisha Patel', $pin));

    expect($errors['pin'][0] ?? null)->toContain($message)
        ->and(TillUser::withoutCompanyScope()->where('name', 'Aisha Patel')->exists())->toBeFalse();
})->with([
    ['12', '4 to 8 digits'], ['12a4', '4 to 8 digits'], ['123456789', '4 to 8 digits'],
    ['1111', 'harder to guess'], ['1234', 'harder to guess'], ['9876', 'harder to guess'],
    ['7391', 'This PIN cannot be used'],
]);

test('names are unique, the role and shops must be the business\'s own', function () {
    $other = Company::factory()->create();

    expect(($this->errors)(fn () => Staff::member($this->company, 'imran khan', '5820')))->toHaveKey('name')
        ->and(($this->errors)(fn () => Staff::member($this->company, 'Aisha', '5820', '01K5T0Q8C4000000000000G999')))->toHaveKey('role_id');

    $shop = Branch::factory()->forCompany($other)->create();
    expect(($this->errors)(fn () => Staff::member($this->company, 'Aisha', '5820', Staff::CASHIER, ['branch_ids' => [$shop->id]])))->toHaveKey('branch_ids');
});

test('editing leaves the PIN and fob alone and only saves real changes', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821');
    app(AssignStaffFob::class)->handle($this->company, $member->id, '04A1B2C3');
    $before = TillUser::withoutCompanyScope()->findOrFail($member->id);

    app(SaveStaffMember::class)->handle($this->company, $member->id, [
        'name' => 'Aisha Patel-Shah', 'role_id' => Staff::MANAGER, 'rate_per_hour' => '12.5', 'is_active' => true,
        'simple_mode_override' => false, 'branch_ids' => [$this->sync->bradford->id],
    ]);
    $after = TillUser::withoutCompanyScope()->findOrFail($member->id);

    expect($after->pin_hash)->toBe($before->pin_hash)
        ->and($after->rfid)->toBe('04A1B2C3')
        ->and([$after->name, $after->role_id, $after->rate_per_hour, $after->simple_mode_override])->toBe(['Aisha Patel-Shah', Staff::MANAGER, '12.50', false])
        ->and(StaffBranch::withoutCompanyScope()->where('till_user_id', $member->id)->pluck('branch_id')->all())->toBe([$this->sync->bradford->id]);
});

test('resetting a PIN replaces the hash; the audit keeps no PIN', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821');
    app(SetStaffPin::class)->handle($this->company, $member->id, '5930');
    $row = TillUser::withoutCompanyScope()->findOrFail($member->id);

    expect((new TillPinHasher)->verify('5930', $row->pin_hash))->toBeTrue()
        ->and((new TillPinHasher)->verify('4821', $row->pin_hash))->toBeFalse()
        ->and(($this->errors)(fn () => app(SetStaffPin::class)->handle($this->company, $member->id, '7391')))->toHaveKey('pin');

    $audit = AuditLog::query()->where('action', 'staff.pin_changed')->sole();
    expect(json_encode($audit->toArray()))->not->toContain('5930')->not->toContain($row->pin_hash);
});

test('a fob is assigned or replaced, never shared, and never written to the audit log', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821');
    app(AssignStaffFob::class)->handle($this->company, $member->id, ' 04a1b2c3 ');
    app(AssignStaffFob::class)->handle($this->company, $member->id, '04FFEE01');

    expect(TillUser::withoutCompanyScope()->findOrFail($member->id)->rfid)->toBe('04FFEE01')
        ->and(($this->errors)(fn () => app(AssignStaffFob::class)->handle($this->company, $this->owner->id, '04ffee01')))->toHaveKey('rfid')
        ->and(($this->errors)(fn () => app(AssignStaffFob::class)->handle($this->company, $this->owner->id, 'x!')))->toHaveKey('rfid')
        ->and(AuditLog::query()->where('action', 'like', 'staff.fob_%')->pluck('action')->all())->toEqualCanonicalizing(['staff.fob_assigned', 'staff.fob_replaced'])
        ->and(AuditLog::query()->where('action', 'like', 'staff.fob_%')->get()->toJson())->not->toContain('04FFEE01')->not->toContain('04a1b2c3');
});

test('the business always keeps one active Owner', function () {
    $keep = fn (array $changes) => ($this->errors)(fn () => app(SaveStaffMember::class)->handle($this->company, $this->owner->id, [
        'name' => 'Imran Khan', 'role_id' => Staff::OWNER, 'is_active' => true, ...$changes,
    ]));

    expect($keep(['is_active' => false]))->toHaveKey('role_id')
        ->and($keep(['role_id' => Staff::MANAGER]))->toHaveKey('role_id')
        ->and(($this->errors)(fn () => app(DeleteStaffMember::class)->handle($this->company, $this->owner->id)))->toHaveKey('status');

    // With a second Owner, the first can step down and be removed.
    Staff::member($this->company, 'Sara Khan', '5820', Staff::OWNER);
    expect($keep(['role_id' => Staff::MANAGER]))->toBe([]);
    app(DeleteStaffMember::class)->handle($this->company, $this->owner->id);

    expect(TillUser::withoutCompanyScope()->withTrashed()->findOrFail($this->owner->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'staff.deleted')->exists())->toBeTrue();
});

test('the role editor writes only the differences, lists only known keys and never strips the Owner', function () {
    app(SaveRolePermissions::class)->handle($this->company, Staff::MANAGER, ['sale.refund', 'business.apply_all_shops']);
    $result = app(SaveRolePermissions::class)->handle($this->company, Staff::MANAGER, ['business.apply_all_shops', 'sale.no_sale']);

    expect($result)->toBe(['granted' => ['sale.no_sale'], 'removed' => ['sale.refund']])
        ->and(TillRolePermission::withoutCompanyScope()->where('role_id', Staff::MANAGER)->orderBy('permission_key')->pluck('permission_key')->all())
        ->toBe(['business.apply_all_shops', 'sale.no_sale'])
        ->and(($this->errors)(fn () => app(SaveRolePermissions::class)->handle($this->company, Staff::MANAGER, ['made.up_key'])))->toHaveKey('permissions');

    app(SaveRolePermissions::class)->handle($this->company, Staff::OWNER, ['sale.refund']);
    expect(($this->errors)(fn () => app(SaveRolePermissions::class)->handle($this->company, Staff::OWNER, [])))->toHaveKey('role');
});

test('security review L5: a PIN clash does not say a colleague holds it, and is audited', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '5820');

    $errors = ($this->errors)(fn () => Staff::member($this->company, 'Bilal Ahmed', '7391'));
    $changed = ($this->errors)(fn () => app(SetStaffPin::class)->handle($this->company, $member->id, '7391'));

    expect($errors['pin'][0])->toBe('This PIN cannot be used. Choose a different one.')->not->toContain('staff member')
        ->and($changed['pin'][0])->toBe('This PIN cannot be used. Choose a different one.')
        ->and(AuditLog::query()->where('action', 'staff.pin_refused')->where('company_id', $this->company->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'staff.pin_refused')->whereNotNull('meta')->get()->pluck('meta')->toJson())->not->toContain('7391');
});
