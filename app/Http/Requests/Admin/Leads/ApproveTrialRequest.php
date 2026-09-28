<?php

namespace App\Http\Requests\Admin\Leads;

use App\Domain\Leads\Data\TrialSetup;
use App\Domain\Leads\Data\TrialShop;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Requests\Admin\LicenceFormRules;
use App\Http\Requests\Admin\UpfrontPaymentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The approval dialog: shops (name, code, nation, tills) and the plan. ApproveTrial re-checks every rule.
 */
class ApproveTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:approve,lead.
    }

    protected function prepareForValidation(): void
    {
        $this->merge(UpfrontPaymentRules::clean($this));
        $shops = $this->input('shops');

        if (is_array($shops)) {
            $this->merge(['shops' => array_map(fn ($shop) => is_array($shop) ? [
                'name' => trim((string) ($shop['name'] ?? '')),
                'code' => strtoupper(trim((string) ($shop['code'] ?? ''))),
                'nation' => $shop['nation'] ?? Nation::England->value,
                'tills' => $shop['tills'] ?? null,
                'tills_allowed' => $shop['tills_allowed'] ?? null,
            ] : $shop, $shops)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shops' => ['required', 'array', 'min:1', 'max:'.Lead::MAX_SHOPS],
            'shops.*.name' => ['required', 'string', 'max:120'],
            'shops.*.code' => ['required', 'string', 'regex:'.Branch::CODE_PATTERN, 'distinct'],
            'shops.*.nation' => ['required', Rule::enum(Nation::class)],
            'shops.*.tills' => ['required', 'integer', 'min:1', 'max:'.NewTenant::MAX_TILLS],
            'plan_id' => ['nullable', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            // Module 1.11: tills allowed per shop, and kind, length and features for every shop's keys.
            'shops.*.tills_allowed' => ['nullable', 'integer', 'min:1', 'max:'.BranchLicenceSettings::MAX_REGISTERS],
        ] + array_merge(array_diff_key(LicenceFormRules::branch(false), ['max_registers' => true, 'valid_from' => true]), [
            'kind' => ['nullable', Rule::enum(TokenKind::class)],
        ]) + UpfrontPaymentRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shops.required' => 'Add at least one shop.',
            'shops.max' => 'Up to '.Lead::MAX_SHOPS.' shops.',
            'shops.*.name.required' => 'Enter the shop name.',
            'shops.*.code.required' => 'Enter a code.',
            'shops.*.code.regex' => 'Use 2 to 5 capital letters, for example LDS.',
            'shops.*.code.distinct' => 'Each shop needs its own code.',
            'shops.*.tills.min' => 'At least 1 till.',
            'shops.*.tills.max' => 'Up to '.NewTenant::MAX_TILLS.' tills per shop.',
            'plan_id.exists' => 'Choose an active plan.',
            'shops.*.tills_allowed.min' => 'Allow at least 1 till.',
        ] + LicenceFormRules::messages() + UpfrontPaymentRules::messages();
    }

    public function setup(): TrialSetup
    {
        /** @var list<array{name: string, code: string, nation: string, tills: int|string, tills_allowed: int|string|null}> $shops */
        $shops = array_values((array) $this->input('shops'));

        return new TrialSetup(
            shops: array_map(fn (array $shop) => new TrialShop(
                name: $shop['name'],
                code: $shop['code'],
                tills: (int) $shop['tills'],
                nation: Nation::from($shop['nation']),
                tillsAllowed: $shop['tills_allowed'] === null || $shop['tills_allowed'] === '' ? null : (int) $shop['tills_allowed'],
            ), $shops),
            planId: $this->filled('plan_id') ? (string) $this->input('plan_id') : null,
            licence: $this->filled('kind') ? LicenceFormRules::settings($this) : null,
            upfront: UpfrontPaymentRules::payment($this),
        );
    }
}
