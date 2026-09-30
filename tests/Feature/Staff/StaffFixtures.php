<?php

namespace Tests\Feature\Staff;

use App\Domain\Staff\Actions\SaveStaffMember;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillUser;
use Tests\Feature\Sync\PullTestHelpers as Pull;

/** Module 4.5 test data: the till's seeded roles and staff made through the Action. */
final class StaffFixtures
{
    public const OWNER = '01K5T0Q8C4000000000000G001';

    public const MANAGER = '01K5T0Q8C4000000000000G002';

    public const CASHIER = '01K5T0Q8C4000000000000G003';

    /** The till's Owner (system), Manager and Cashier roles, as a till would have sent them. */
    public static function roles(Company $company): void
    {
        Pull::portalCreate($company, 'Role', Pull::payload('Role', self::OWNER, ['name' => 'Owner', 'isSystem' => true, 'level' => 100]));
        Pull::portalCreate($company, 'Role', Pull::payload('Role', self::MANAGER, ['name' => 'Manager', 'isSystem' => true, 'level' => 50]));
        Pull::portalCreate($company, 'Role', Pull::payload('Role', self::CASHIER, ['name' => 'Cashier', 'isSystem' => false, 'level' => 10]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function member(Company $company, string $name, string $pin, string $roleId = self::CASHIER, array $overrides = []): TillUser
    {
        /** @var array{name: string, role_id: string, pin: string} $data */
        $data = ['name' => $name, 'role_id' => $roleId, 'pin' => $pin, 'rate_per_hour' => '11.44', ...$overrides];

        return app(SaveStaffMember::class)->handle($company, null, $data);
    }
}
