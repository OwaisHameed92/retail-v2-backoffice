<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldShops;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Adds a handheld (PDA) login for one open shop of the current business, with the password the manager chose
 * (stored as bcrypt). Prices stay locked unless `can_change_prices`. Audited as `handheld_user.created` (never the
 * password).
 *
 *     $handheld = app(CreateHandheldUser::class)->handle(['name' => 'Stock room 1', 'email' => 'stock1@shop.test',
 *         'branch_id' => $leeds->id, 'password' => '…', 'can_change_prices' => false], $owner);
 */
final class CreateHandheldUser
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array{name: string, email: string, branch_id: string, password: string, can_change_prices?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(#[SensitiveParameter] array $data, User $by): HandheldUser
    {
        $shop = HandheldShops::openShop((string) $data['branch_id']);

        return DB::transaction(function () use ($data, $by, $shop) {
            $handheld = HandheldUser::query()->create([
                'branch_id' => $shop->id,
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'is_active' => true,
                'can_change_prices' => (bool) ($data['can_change_prices'] ?? false),
                'created_by_user_id' => $by->id,
            ]);

            $this->audit->handle('handheld_user.created', $handheld, null, [
                'name' => $handheld->name, 'email' => $handheld->email, 'shop' => $shop->name, 'can_change_prices' => $handheld->can_change_prices,
            ], actor: $by);

            return $handheld;
        });
    }
}
