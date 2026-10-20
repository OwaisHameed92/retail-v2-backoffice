<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldShops;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Edits a handheld user's name, login email, shop and price permission. Moving to another shop applies at the
 * device's next call (every call reads the shop afresh). Audited as `handheld_user.updated` with what changed.
 */
final class UpdateHandheldUser
{
    private const FIELDS = ['name', 'email', 'branch_id', 'can_change_prices'];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array{name: string, email: string, branch_id: string, can_change_prices?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(HandheldUser $handheld, array $data, User $by): HandheldUser
    {
        $shop = $data['branch_id'] === $handheld->branch_id ? null : HandheldShops::openShop((string) $data['branch_id']);
        $before = Arr::only($handheld->only(self::FIELDS), self::FIELDS);

        $handheld->fill([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'branch_id' => $shop->id ?? $handheld->branch_id,
            'can_change_prices' => (bool) ($data['can_change_prices'] ?? false),
        ]);

        $changed = array_keys($handheld->getDirty());

        if ($changed === []) {
            return $handheld;
        }

        $handheld->save();
        $this->audit->handle('handheld_user.updated', $handheld, Arr::only($before, $changed), Arr::only($handheld->only(self::FIELDS), $changed), actor: $by);

        return $handheld;
    }
}
