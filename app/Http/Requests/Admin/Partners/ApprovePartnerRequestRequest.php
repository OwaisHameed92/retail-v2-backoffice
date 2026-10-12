<?php

namespace App\Http\Requests\Admin\Partners;

use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Partners\Data\PartnerApproval;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Data\NewBranch;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Requests\Admin\Billing\BillingRequest;
use App\Http\Requests\Admin\LicenceFormRules;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Approve" on a partner request: the new-business wizard's form (StoreTenantRequest's rules: TenantRules for the
 * business and every shop, the owner login, the plan, the licence form) with one or more shops (`shops.*`, each with
 * its tills and tills allowed, like trial approval), plus the agreed setup fee per till and VAT.
 */
class ApprovePartnerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:tenants.manage.
    }

    protected function prepareForValidation(): void
    {
        $clean = TenantRules::clean($this, ['']);
        $amount = $this->input('setup_fee_per_till');
        $clean['setup_fee_per_till'] = $amount === null || (is_string($amount) && trim($amount) === '') ? null : BillingRequest::cleanMoney($amount);
        $shops = $this->input('shops');

        if (is_array($shops)) {
            $clean['shops'] = array_map(fn ($shop) => is_array($shop) ? array_replace($shop, TenantRules::cleanValues($shop, [''])) : $shop, array_values($shops));
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Own rules first (as StoreTenantRequest; `+` keeps the first): `owner_name` is the owner login here (also the
        // keys' owner, cut to 80) and a shop code must differ from the other shops'.
        return [
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'shops' => ['required', 'array', 'min:1', 'max:'.Lead::MAX_SHOPS],
            'shops.*.code' => ['required', 'string', 'regex:'.Branch::CODE_PATTERN, 'distinct'],
            'shops.*.tills' => ['required', 'integer', 'min:1', 'max:'.NewTenant::MAX_TILLS],
            'shops.*.tills_allowed' => ['nullable', 'integer', 'min:1', 'max:'.BranchLicenceSettings::MAX_REGISTERS],
            'status' => ['required', Rule::in([CompanyStatus::Trial->value, CompanyStatus::Active->value])],
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'setup_fee_per_till' => ['nullable', 'string', 'regex:'.BillingRequest::moneyPattern()],
            'vat_applies' => ['boolean'],
        ] + TenantRules::company() + TenantRules::branch('shops.*.') + array_merge(array_diff_key(LicenceFormRules::branch(false), ['max_registers' => true, 'valid_from' => true]), [
            'kind' => ['nullable', Rule::enum(TokenKind::class)],
        ]) + LicenceFormRules::limits();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages('shops.*.') + [
            'name.required' => 'Enter the business name.',
            'shops.required' => 'Add at least one shop.',
            'shops.max' => 'Up to '.Lead::MAX_SHOPS.' shops.',
            'shops.*.name.required' => 'Enter the shop name.',
            'shops.*.code.required' => 'Enter a short branch code, for example LDS.',
            'shops.*.code.distinct' => 'Each shop needs its own code.',
            'shops.*.tills.min' => 'A shop needs at least 1 till.',
            'shops.*.tills.max' => 'Up to '.NewTenant::MAX_TILLS.' tills per shop.',
            'shops.*.tills_allowed.min' => 'Allow at least 1 till.',
            'owner_name.required' => 'Enter the owner’s name.',
            'owner_email.required' => 'Enter the owner’s email. We send them a link to set their password.',
            'plan_id.required' => 'Choose a plan.',
            'plan_id.exists' => 'Choose an active plan.',
            'setup_fee_per_till.regex' => BillingRequest::moneyMessage(),
        ] + LicenceFormRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['owner_email' => 'owner email', 'shops.*.code' => 'branch code', 'shops.*.name' => 'shop name'];
    }

    public function approval(): PartnerApproval
    {
        /** @var list<array<string, mixed>> $shops */
        $shops = array_values((array) $this->input('shops'));
        $branches = [];

        foreach ($shops as $index => $shop) {
            $tills = (int) $shop['tills'];
            $allowed = isset($shop['tills_allowed']) && $shop['tills_allowed'] !== '' ? max($tills, (int) $shop['tills_allowed']) : $tills;
            $branches[] = new NewBranch(TenantRules::branchDetails($this, "shops.{$index}."), $tills, $allowed);
        }

        $first = array_shift($branches);
        $count = count($shops);
        $licence = $this->filled('kind') ? LicenceFormRules::settings($this) : new BranchLicenceSettings;
        $multiBranch = $count > 1 || $this->boolean('multi_branch');

        return new PartnerApproval(
            tenant: new NewTenant(
                company: TenantRules::companyDetails($this),
                branch: $first->details,
                tills: $first->tills,
                ownerName: (string) $this->input('owner_name'),
                ownerEmail: (string) $this->input('owner_email'),
                status: CompanyStatus::from((string) $this->input('status')),
                planId: (string) $this->input('plan_id'),
                moreBranches: $branches,
                licence: $licence->withMaxRegisters(max($first->tills, $first->tillsAllowed ?? 1)),
                multiBranch: $multiBranch,
                maxBranches: $multiBranch ? max($count, $this->integer('max_branches', $count)) : 1,
            ),
            setupFeePerTill: $this->filled('setup_fee_per_till') ? Money::normalise((string) $this->input('setup_fee_per_till')) : null,
            vatApplies: $this->boolean('vat_applies', true),
        );
    }
}
