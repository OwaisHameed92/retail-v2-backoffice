<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillData\Sync\SyncRowIds;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\WorkshopSwitch;

/**
 * Writes the branch setting `workshop.connector_status` (pending / connected / disconnected / failed) for the
 * connection's shop (WORKSHOP-BIKEBOOK.md §1.1, §6): the portal is its only writer and the till only shows it. Stored
 * like a portal setting edit (SaveTillSetting's keyed row id), so the shop's till gets it in its next pull. The
 * generic settings editor still refuses the key (SettingSyncPolicy::TILL_WRITES_ONLY); only this action writes it,
 * and only while WORKSHOP_CONNECTOR is on (PullVisibility keeps the key out of every pull while it is off).
 * `workshop.connection_status` (the till's own Direct-mode check) is never written.
 */
final class WriteConnectorStatus
{
    public const KEY = 'workshop.connector_status';

    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function handle(WorkshopConnection $connection): void
    {
        if (! WorkshopSwitch::enabled()) {
            return;
        }

        $company = Company::query()->findOrFail($connection->company_id);
        $id = SyncRowIds::setting(SettingScope::Branch->value, $connection->branch_id, self::KEY);
        $value = $connection->status->value;

        $this->tenancy->runAs($company, function () use ($id, $connection, $value): void {
            $row = TillSetting::withTrashed()->find($id) ?? TillSetting::query()
                ->where('scope', SettingScope::Branch->value)->where('scope_id', $connection->branch_id)->where('setting_key', self::KEY)->first();

            if ($row !== null && ! $row->trashed() && $row->value === $value) {
                return;
            }

            $row ??= new TillSetting;
            $row->forceFill([
                'id' => $row->exists ? $row->id : $id, 'scope' => SettingScope::Branch, 'scope_id' => $connection->branch_id,
                'setting_key' => self::KEY, 'value' => $value, 'deleted_at' => null,
            ])->save();
        });
    }
}
