<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Saves a business's mapping for one package (gap #8). A blank value (or one equal to the default) removes the
 * business's own row, so the default applies again. Audited with the codes that changed.
 *
 *     app(SaveExportMappings::class)->handle($company, ExportTarget::Xero, ['4000' => '201'], ['S' => ['20% (VAT on Income)', '20% (VAT on Expenses)']]);
 */
final class SaveExportMappings
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, string|null>  $accounts  our code => their code
     * @param  array<string, array{0: string|null, 1: string|null}>  $vat  our VAT code => [sales, purchases]
     * @return int how many codes changed
     */
    public function handle(Company $company, ExportTarget $target, array $accounts, array $vat): int
    {
        return $this->tenancy->runAs($company, fn (Company $company): int => DB::transaction(function () use ($company, $target, $accounts, $vat): int {
            $before = ExportMappings::for($target);
            $defaults = ExportDefaults::accounts($target);
            $vatDefaults = ExportDefaults::vat($target);
            $changed = [];

            foreach ($accounts as $code => $their) {
                $their = trim((string) $their);
                $value = $their === '' || $their === ($defaults[$code] ?? null) ? null : $their;

                if ($value !== ($before->ownAccounts[$code] ?? null)) {
                    $this->put(AccountingExportMapping::KIND_ACCOUNT, $target, (string) $code, $value, null);
                    $changed[] = (string) $code;
                }
            }

            foreach ($vat as $code => [$sales, $purchases]) {
                $sales = trim((string) $sales);
                $purchases = trim((string) $purchases);
                $default = $vatDefaults[$code] ?? null;
                $pair = $sales === '' && $purchases === '' ? null : [$sales !== '' ? $sales : ($default[0] ?? ''), $purchases !== '' ? $purchases : ($default[1] ?? $sales)];
                $pair = $pair === $default ? null : $pair;

                if ($pair !== ($before->ownVat[$code] ?? null)) {
                    $this->put(AccountingExportMapping::KIND_VAT, $target, (string) $code, $pair[0] ?? null, $pair[1] ?? null);
                    $changed[] = Country::tax('VAT').' '.$code;
                }
            }

            if ($changed !== []) {
                $this->audit->handle('accounts.export_mapping_updated', null, null, null, ['package' => $target->label(), 'codes' => implode(', ', $changed)], companyId: $company->id);
            }

            return count($changed);
        }));
    }

    private function put(string $kind, ExportTarget $target, string $code, ?string $their, ?string $purchase): void
    {
        $query = AccountingExportMapping::query()->where('target', $target->value)->where('kind', $kind)->where('our_code', $code);

        if ($their === null) {
            $query->delete();

            return;
        }

        $row = $query->first() ?? new AccountingExportMapping(['target' => $target->value, 'kind' => $kind, 'our_code' => $code]);
        $row->forceFill(['their_code' => mb_substr($their, 0, 100), 'their_purchase_code' => $purchase === null ? null : mb_substr($purchase, 0, 100)])->save();
    }
}
