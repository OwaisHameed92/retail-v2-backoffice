<?php

namespace App\Domain\Staff\Support;

use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Validation\ValidationException;

/**
 * The rules every staff change shares (module 4.5): a PIN the till accepts and no colleague already uses, a fob no
 * colleague holds, and a business that always keeps one active member in the till's system Owner role (the till
 * needs someone who can do everything). Call inside the company scope.
 */
final class StaffGuards
{
    public function __construct(private readonly TillPinHasher $hasher) {}

    /**
     * @throws ValidationException
     */
    public function checkPin(string $pin, ?string $exceptId, string $field = 'pin'): void
    {
        if (preg_match('/^\d{4,8}$/', $pin) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a PIN of 4 to 8 digits.']);
        }

        if (self::guessable($pin)) {
            throw ValidationException::withMessages([$field => 'Choose a PIN that is harder to guess: not one repeated digit or a run like 1234.']);
        }

        $hashes = TillUser::query()
            ->where('is_active', true)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('pin_hash', '!=', '')
            ->pluck('pin_hash');

        foreach ($hashes as $hash) {
            if ($this->hasher->verify($pin, is_string($hash) ? $hash : null)) {
                throw ValidationException::withMessages([$field => 'Another staff member already uses this PIN. Choose a different one.']);
            }
        }
    }

    /**
     * The fob's code exactly as the reader types it (trimmed), once no colleague holds it.
     *
     * @throws ValidationException
     */
    public function checkFob(string $rfid, ?string $exceptId): string
    {
        $rfid = trim($rfid);

        if (preg_match('/^[A-Za-z0-9:\-]{4,64}$/', $rfid) !== 1) {
            throw ValidationException::withMessages(['rfid' => 'Scan the fob or type its code: 4 to 64 letters, digits, colons or dashes.']);
        }

        $taken = TillUser::query()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('UPPER(rfid) = ?', [strtoupper($rfid)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['rfid' => 'This fob already belongs to another staff member.']);
        }

        return $rfid;
    }

    /**
     * Refuses a change that would leave no active member in the system Owner role.
     *
     * @throws ValidationException
     */
    public function keepAnOwner(TillUser $member, bool $staysOwner, string $field = 'status'): void
    {
        if ($staysOwner || ! $member->is_active || ! $this->isOwnerRole($member->role_id)) {
            return;
        }

        $others = TillUser::query()->whereKeyNot($member->id)->where('is_active', true)
            ->whereIn('role_id', $this->ownerRoleIds())->exists();

        if (! $others) {
            throw ValidationException::withMessages([$field => "{$member->name} is the only active Owner on the tills. Give someone else the Owner role first."]);
        }
    }

    public function isOwnerRole(?string $roleId): bool
    {
        return $roleId !== null && in_array($roleId, $this->ownerRoleIds(), true);
    }

    /** @return list<string> */
    private function ownerRoleIds(): array
    {
        return TillRole::query()->where('is_system', true)->get(['id', 'name'])
            ->filter(fn (TillRole $role) => strcasecmp($role->name, 'Owner') === 0)
            ->pluck('id')->values()->all();
    }

    private static function guessable(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('01234567890', $pin) || str_contains('09876543210', $pin);
    }
}
