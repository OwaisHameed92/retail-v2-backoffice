<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldAccess;
use App\Domain\Handheld\Support\HandheldShops;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Adds a handheld (PDA) login for one open shop of the current business, with the password the manager chose
 * (stored as bcrypt). Prices, cost and stock stay locked unless `can_change_prices` / `can_change_cost` /
 * `can_adjust_stock`. Audited as `handheld_user.created` (never the
 * password). Only when the business's plan includes the handheld app (HandheldAccess::allowed, owner 2026-10-21):
 * otherwise a validation error on `plan`, whatever the screen showed.
 *
 *     $handheld = app(CreateHandheldUser::class)->handle(['name' => 'Stock room 1', 'email' => 'stock1@shop.test',
 *         'branch_id' => $leeds->id, 'password' => '…', 'can_change_prices' => false], $owner);
 */
final class CreateHandheldUser
{
    public const NOT_INCLUDED = 'The handheld app comes with the monthly plans. Contact Switch & Save to upgrade before adding handheld users.';

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array{name: string, email: string, branch_id: string, password: string, can_change_prices?: bool, can_change_cost?: bool, can_adjust_stock?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(#[SensitiveParameter] array $data, User $by): HandheldUser
    {
        if (! HandheldAccess::allowed($this->tenancy->require())) {
            throw ValidationException::withMessages(['plan' => self::NOT_INCLUDED]);
        }

        $shop = HandheldShops::openShop((string) $data['branch_id']);

        return DB::transaction(function () use ($data, $by, $shop) {
            $handheld = HandheldUser::query()->create([
                'branch_id' => $shop->id,
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'is_active' => true,
                'can_change_prices' => (bool) ($data['can_change_prices'] ?? false),
                'can_change_cost' => (bool) ($data['can_change_cost'] ?? false),
                'can_adjust_stock' => (bool) ($data['can_adjust_stock'] ?? false),
                'created_by_user_id' => $by->id,
            ]);

            $this->audit->handle('handheld_user.created', $handheld, null, [
                'name' => $handheld->name, 'email' => $handheld->email, 'shop' => $shop->name, 'can_change_prices' => $handheld->can_change_prices,
                'can_change_cost' => $handheld->can_change_cost, 'can_adjust_stock' => $handheld->can_adjust_stock,
            ], actor: $by);

            return $handheld;
        });
    }
}
