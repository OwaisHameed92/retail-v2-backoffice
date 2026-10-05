<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Support\IdTranslator;
use App\Domain\Tenancy\Models\Register;

/**
 * The shop's company and branch ids as its tills know them, for the top level of `licence/activate` and
 * `licence/validate` replies (ANSWERS-2026-10-06 "Purane khule sawal" 1, KEY-CARRIES-SHOP.md). Always the shop's,
 * never blank and never the calling till's own when it differs: a till takes an `apiKey` only when these match its
 * own database, so an extra till installed on its own (its own ids until it joins the main till) never starts
 * syncing under them.
 *
 * The shop's ids are the ones its main till sent (`id_map` rows the main till's install wrote), else the first a till
 * of the shop sent (the main till usually activates first), else what pull would send (IdTranslator::toTill), which
 * is our own id when no till mapped one.
 */
final class ShopTillIds
{
    /**
     * @return array{companyId: string, branchId: string}
     */
    public static function for(Licence $licence): array
    {
        $rows = IdMapping::withoutCompanyScope()->where('company_id', $licence->company_id)->where('branch_id', $licence->branch_id)
            ->whereIn('kind', [IdKind::Company->value, IdKind::Branch->value])->orderBy('id')->get();
        $main = self::mainInstall($licence);
        $pick = function (IdKind $kind, string $portalId) use ($rows, $main): ?string {
            $mine = $rows->filter(fn (IdMapping $row) => $row->kind === $kind && $row->portal_id === $portalId);

            $row = ($main !== null ? $mine->firstWhere('install_id', $main) : null) ?? $mine->first();

            return $row?->till_id;
        };
        $translator = null;
        $translate = function (IdKind $kind, string $portalId) use ($licence, &$translator): string {
            $translator ??= IdTranslator::forCompany($licence->company_id);

            return $translator->toTill($kind, $portalId, $licence->branch_id);
        };

        return [
            'companyId' => $pick(IdKind::Company, $licence->company_id) ?? $translate(IdKind::Company, $licence->company_id),
            'branchId' => $pick(IdKind::Branch, $licence->branch_id) ?? $translate(IdKind::Branch, $licence->branch_id),
        ];
    }

    /** The install the shop's main till's key is bound to, if any. */
    private static function mainInstall(Licence $licence): ?string
    {
        $register = Register::withoutCompanyScope()->where('branch_id', $licence->branch_id)->where('is_main_till', true)->value('id');

        if ($register === null) {
            return null;
        }

        $install = Licence::withoutCompanyScope()->where('register_id', $register)->whereNotNull('device_id')->value('device_id');

        return is_string($install) ? $install : null;
    }
}
