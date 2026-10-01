<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\DB;

/**
 * The members of a business and how each wants to hear about every alert type (module 7.8): active memberships
 * only, choices from `alert_preferences`, else the role's default; a type the role cannot see is always off.
 */
final class AlertRecipients
{
    /**
     * @return list<Recipient>
     */
    public static function for(string $companyId): array
    {
        $members = DB::table('company_user as cu')->join('users as u', 'u.id', '=', 'cu.user_id')
            ->where('cu.company_id', $companyId)->where('cu.is_active', true)
            ->orderBy('u.id')->get(['u.id', 'u.name', 'u.email', 'cu.role', 'cu.branch_id']);

        $preferences = AlertPreference::withoutCompanyScope()->where('company_id', $companyId)
            ->whereIn('user_id', $members->pluck('id')->all())->get()->keyBy('user_id');

        $out = [];

        foreach ($members as $member) {
            $role = CompanyRole::tryFrom((string) $member->role);

            if ($role === null || ! filter_var($member->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $preference = $preferences->get((int) $member->id);
            $restricted = $member->branch_id !== null ? (string) $member->branch_id : null;
            $out[] = new Recipient(
                userId: (int) $member->id,
                name: (string) $member->name,
                email: (string) $member->email,
                role: $role,
                restrictedBranchId: $restricted,
                deliveries: self::deliveries($role, $preference->deliveries ?? []),
                branchIds: $restricted === null ? $preference?->branch_ids : null,
            );
        }

        return $out;
    }

    /**
     * One user's recipient record in a business, or null when they are not an active member.
     */
    public static function find(string $companyId, int $userId): ?Recipient
    {
        foreach (self::for($companyId) as $recipient) {
            if ($recipient->userId === $userId) {
                return $recipient;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $chosen
     * @return array<string, AlertDelivery>
     */
    public static function deliveries(CompanyRole $role, array $chosen): array
    {
        $out = [];

        foreach (AlertType::cases() as $type) {
            $delivery = AlertDelivery::tryFrom((string) ($chosen[$type->value] ?? ''));

            $out[$type->value] = match (true) {
                ! $role->can($type->ability()) => AlertDelivery::Off,
                $delivery !== null && in_array($delivery, $type->deliveries(), true) => $delivery,
                default => $type->defaultFor($role),
            };
        }

        return $out;
    }
}
