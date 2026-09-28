<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Leads\Data\TrialSetup;
use App\Domain\Leads\Data\TrialShop;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Data\NewBranch;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Approve 7-day trial": turns an open lead into a tenant through CreateTenant, so the usual hooks run: every
 * till gets a licence on the plan, the owner (the lead's contact) gets the welcome email with the keys and, when
 * new, the 7-day "set your password" email. Billing (module 1.13): Direct Debit set up by the owner in the portal
 * within the deadline, and the upfront payment when staff recorded one. The company starts as a trial with no end date: the trial starts on
 * the first till activation. The lead becomes converted and links to the company.
 *
 * Idempotent: the lead row is locked and re-checked inside the transaction, and `leads.company_id` is unique, so a
 * double click or a second admin gets a clear error and nothing is created twice.
 */
class ApproveTrial
{
    public function __construct(
        private readonly CreateTenant $createTenant,
        private readonly OnboardTenantBilling $onboardBilling,
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, TrialSetup $setup): Company
    {
        self::ensureApprovable($lead);
        $this->validateShops($setup);
        $plan = $this->plan($setup);

        return DB::transaction(function () use ($lead, $setup, $plan) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['status' => 'This lead is archived. Restore it before approving a trial.']);
            }

            self::ensureApprovable($locked);

            $shops = $setup->shops;
            $first = array_shift($shops);

            $company = $this->createTenant->handle(new NewTenant(
                company: new CompanyDetails(
                    name: $locked->business_name,
                    address: implode(', ', array_filter([$locked->town, $locked->postcode])) ?: null,
                    phone: $locked->phone,
                    email: $locked->email,
                    contactName: $locked->contact_name,
                    notes: $this->companyNotes($locked),
                    businessType: BusinessType::fromLead($locked->business_type->value),
                    town: $locked->town,
                    postcode: $locked->postcode,
                    ownerName: $locked->contact_name,
                ),
                branch: $this->branch($first),
                tills: $first->tills,
                ownerName: $locked->contact_name,
                ownerEmail: (string) $locked->email,
                status: CompanyStatus::Trial,
                planId: $plan->id,
                moreBranches: array_map(fn (TrialShop $shop) => new NewBranch($this->branch($shop), $shop->tills, $shop->tillsAllowed), $shops),
                licence: ($setup->licence ?? new BranchLicenceSettings)->withMaxRegisters(max($first->tills, $first->tillsAllowed ?? 1)),
                multiBranch: count($setup->shops) > 1,
                maxBranches: count($setup->shops),
            ));

            // Module 1.13: Direct Debit set up by the owner in the portal, and the upfront payment if staff took one.
            $this->onboardBilling->handle($company, $setup->upfront);

            $from = $locked->status;
            $admin = $this->timeline->currentAdmin();

            $locked->status = LeadStatus::Converted;
            $locked->company_id = $company->id;
            $locked->converted_at = now();
            $locked->converted_by_admin_id = $admin?->id;
            $locked->follow_up_at = null;
            $locked->save();

            $shopCount = count($setup->shops);
            $tillCount = $setup->totalTills();

            $this->timeline->record(
                $locked,
                LeadNoteKind::StatusChanged,
                "Approved a {$plan->trial_days}-day trial: created {$company->name} with ".MailFormat::count($shopCount, 'shop').' and '.MailFormat::count($tillCount, 'till')." on {$plan->name}",
                ['from' => $from->value, 'to' => LeadStatus::Converted->value, 'company_id' => $company->id],
            );

            $this->audit->handle('lead.converted', $locked, ['status' => $from->value], [
                'status' => LeadStatus::Converted->value,
                'company_id' => $company->id,
            ], [
                'lead_id' => $locked->id,
                'shops' => $shopCount,
                'tills' => $tillCount,
                'plan' => $plan->code,
                'owner_email' => $locked->email,
            ], companyId: $company->id);

            $lead->setRawAttributes($locked->getAttributes(), true);
            $lead->setRelation('company', $company);

            return $company;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function ensureApprovable(Lead $lead): void
    {
        if ($lead->company_id !== null || $lead->isConverted()) {
            $name = $lead->company->name ?? 'the tenant';

            throw ValidationException::withMessages(['status' => "This trial is already approved: {$name} was created".($lead->converted_at ? ' on '.MailFormat::date($lead->converted_at) : '').'.']);
        }

        if ($lead->trashed()) {
            throw ValidationException::withMessages(['status' => 'This lead is archived. Restore it before approving a trial.']);
        }

        if (! $lead->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only new or contacted leads can be approved. Reopen this lead first.']);
        }

        if ($lead->email === null) {
            throw ValidationException::withMessages(['email' => 'Add the contact’s email first: the owner signs in with it and gets the licence keys by email.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateShops(TrialSetup $setup): void
    {
        $errors = [];
        $count = count($setup->shops);

        if ($count < 1 || $count > Lead::MAX_SHOPS) {
            throw ValidationException::withMessages(['shops' => 'Add between 1 and '.Lead::MAX_SHOPS.' shops.']);
        }

        $codes = [];

        foreach ($setup->shops as $index => $shop) {
            $code = strtoupper(trim($shop->code));

            if (trim($shop->name) === '' || mb_strlen(trim($shop->name)) > 120) {
                $errors["shops.{$index}.name"] = 'Enter the shop name (up to 120 characters).';
            }

            if (preg_match(Branch::CODE_PATTERN, $code) !== 1) {
                $errors["shops.{$index}.code"] = 'Use 2 to 5 capital letters, for example LDS.';
            } elseif (in_array($code, $codes, true)) {
                $errors["shops.{$index}.code"] = "Another shop already uses {$code}. Each shop needs its own code.";
            }

            if ($shop->tills < 1 || $shop->tills > NewTenant::MAX_TILLS) {
                $errors["shops.{$index}.tills"] = 'Choose between 1 and '.NewTenant::MAX_TILLS.' tills.';
            }

            $codes[] = $code;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function plan(TrialSetup $setup): Plan
    {
        $plan = $setup->planId !== null
            ? Plan::query()->whereKey($setup->planId)->where('is_active', true)->first()
            : DefaultPlan::portal();

        if ($plan === null) {
            throw ValidationException::withMessages(['plan_id' => $setup->planId !== null
                ? 'Choose an active plan.'
                : 'There is no active plan yet, so the tills would have no licence keys. Create a plan first.']);
        }

        return $plan;
    }

    private function branch(TrialShop $shop): BranchDetails
    {
        return new BranchDetails(code: strtoupper(trim($shop->code)), name: trim($shop->name), nation: $shop->nation);
    }

    private function companyNotes(Lead $lead): string
    {
        $lines = ['From a trial request received on '.MailFormat::date($lead->created_at ?? now()).' ('.mb_strtolower($lead->source->label()).').'];

        if ($lead->current_system !== null) {
            $lines[] = "Current system: {$lead->current_system}.";
        }

        return implode(' ', $lines);
    }
}
