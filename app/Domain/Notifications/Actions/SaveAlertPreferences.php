<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Notifications\Support\AlertRecipients;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Saves a portal user's own alert choices in one business (module 7.8). Types the role cannot see and deliveries a
 * type does not offer ("straight away" for a digest-only type) are dropped. A one-shop user never picks shops; a
 * multi-shop user's pick is kept to the business's shops, and picking all of them means "every shop", new ones too.
 */
class SaveAlertPreferences
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, string>  $deliveries  AlertType value → AlertDelivery value
     * @param  list<string>|null  $branchIds  null = every shop
     */
    public function handle(Company $company, int $userId, CompanyRole $role, ?string $restrictedBranchId, array $deliveries, ?array $branchIds): AlertPreference
    {
        $clean = [];

        foreach (AlertType::forRole($role) as $type) {
            $delivery = AlertDelivery::tryFrom((string) ($deliveries[$type->value] ?? ''));

            if ($delivery !== null && in_array($delivery, $type->deliveries(), true)) {
                $clean[$type->value] = $delivery->value;
            }
        }

        $shops = null;

        if ($restrictedBranchId === null && $branchIds !== null) {
            $all = DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (string) $id)->all();
            $picked = array_values(array_intersect($all, $branchIds));
            $shops = count($picked) === count($all) ? null : $picked;
        }

        $preference = AlertPreference::withoutCompanyScope()->firstOrNew(['company_id' => $company->id, 'user_id' => $userId]);
        $before = $preference->exists ? ['deliveries' => $preference->deliveries, 'shops' => $preference->branch_ids] : null;
        $preference->fill(['deliveries' => $clean, 'branch_ids' => $shops])->save();

        $this->audit->handle('alerts.preferences_updated', $preference, $before, [
            'deliveries' => array_map(fn (AlertDelivery $d) => $d->value, AlertRecipients::deliveries($role, $clean)),
            'shops' => $shops,
        ], companyId: $company->id);

        return $preference;
    }
}
