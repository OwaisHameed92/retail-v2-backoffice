<?php

namespace App\Domain\ShopSettings\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the Till settings page (module 4.9, contract §10.3, §18.6): for every shop (company scope) or one shop
 * (branch scope, which wins over every shop's value on that shop's tills). Only catalogue keys (SettingCatalogue,
 * never a deny-listed one); each value is normalised to the till's text, and a blank removes the setting here so
 * the wider value (or the till's default) applies again. Written through SaveTillSetting, so the tills get it in
 * their next pull (≈ 30 seconds online). Values that did not change are left alone. One audit entry
 * (`till_settings.updated`) lists the keys and their old and new values.
 *
 *     app(SaveShopSettings::class)->handle($company, $leeds, ['receipt.footer_text' => 'Thank you', 'cash.count_on_close' => true]);
 */
final class SaveShopSettings
{
    public function __construct(
        private readonly SaveTillSetting $save,
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  catalogue key => value (null or blank = use the wider setting)
     * @return list<string> the keys that changed
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?Branch $branch, array $values): array
    {
        if ($branch !== null && $branch->company_id !== $company->id) {
            throw ValidationException::withMessages(['shop' => 'Choose one of this business\'s shops.']);
        }

        $wanted = $this->normalise($values);
        $scope = $branch === null ? SettingScope::Company : SettingScope::Branch;
        $scopeId = $branch === null ? $company->id : $branch->id;

        return $this->tenancy->runAs($company, fn (): array => DB::transaction(function () use ($company, $branch, $wanted, $scope, $scopeId): array {
            $current = TillSetting::query()->where('scope', $scope->value)->where('scope_id', $scopeId)
                ->whereIn('setting_key', array_keys($wanted))->pluck('value', 'setting_key')->all();
            $before = $after = [];

            foreach ($wanted as $key => $value) {
                if (($current[$key] ?? null) === $value) {
                    continue;
                }

                $this->save->handle($company, $scope, $branch, $key, $value);
                $before[$key] = $current[$key] ?? null;
                $after[$key] = $value;
            }

            if ($after !== []) {
                $this->audit->handle('till_settings.updated', $branch ?? $company, $before, $after, [
                    'scope' => $scope->value, 'branch_id' => $branch?->id,
                ], companyId: $company->id);
            }

            return array_keys($after);
        }));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function normalise(array $values): array
    {
        $wanted = $errors = [];

        foreach ($values as $key => $value) {
            try {
                $wanted[(string) $key] = SettingCatalogue::normalise((string) $key, $value);
            } catch (ValidationException $e) {
                $errors = [...$errors, ...$e->errors()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $wanted;
    }
}
