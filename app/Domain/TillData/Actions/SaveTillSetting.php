<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Validation\ValidationException;

/**
 * A shared till setting changed on the portal (contract v1.4 §10.3): one keyed row per (scope, scope id, key), stored
 * under the id derived from it (SyncRowIds) so the portal's row and a till's push of the same setting are one row.
 * The pull sends it to every till of the company (company scope) or to the branch's till (branch scope). A `null`
 * value removes the setting (soft delete, pulled as `D`: the till falls back to the wider scope or its default).
 *
 * Refused: register scope and every deny-listed key (SettingSyncPolicy: device, licence and sync settings, secrets),
 * which never leave a till and are never applied from a pull; values over 4,000 characters. The settings editor
 * (Phase 4) calls this; the value is text exactly as the till stores it ("true", "20", "Thank you for shopping!").
 *
 *     app(SaveTillSetting::class)->handle($company, SettingScope::Company, null, 'receipt.footer_text', 'Thank you');
 */
final class SaveTillSetting
{
    public const MAX_VALUE = 4000;

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, SettingScope $scope, ?Branch $branch, string $key, ?string $value): ?TillSetting
    {
        $key = trim($key);

        if ($scope === SettingScope::Register || SettingSyncPolicy::isLocalOnly($scope->value, $key)) {
            throw ValidationException::withMessages(['key' => 'This setting belongs to each till and cannot be changed from the portal.']);
        }

        if ($scope === SettingScope::Branch && ($branch === null || $branch->company_id !== $company->id)) {
            throw ValidationException::withMessages(['branch' => 'Choose one of this business\'s shops.']);
        }

        if ($value !== null && mb_strlen($value) > self::MAX_VALUE) {
            throw ValidationException::withMessages(['value' => 'A setting can be at most '.number_format(self::MAX_VALUE).' characters.']);
        }

        $scopeId = $scope === SettingScope::Company ? $company->id : $branch->id;
        $id = SyncRowIds::setting($scope->value, $scopeId, $key);

        return $this->tenancy->runAs($company, function () use ($id, $scope, $scopeId, $key, $value): ?TillSetting {
            $row = TillSetting::withTrashed()->find($id);

            if ($value === null) {
                if ($row !== null && ! $row->trashed()) {
                    $row->delete();
                }

                return $row;
            }

            $row ??= new TillSetting;

            if ($row->exists && ! $row->trashed() && $row->value === $value) {
                return $row; // The same value again changes nothing (§10.3).
            }

            $row->forceFill([
                'id' => $id, 'scope' => $scope, 'scope_id' => $scopeId, 'setting_key' => $key, 'value' => $value, 'deleted_at' => null,
            ])->save();

            return $row;
        });
    }
}
