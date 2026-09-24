<?php

namespace App\Http\Requests\Admin\Leads;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The admin "Add lead" and "Edit lead" forms. Assignment and follow-up are only on the add form.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead
            ? ($this->user('admin')?->can('update', $lead) ?? false)
            : ($this->user('admin')?->can('create', Lead::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['business_name', 'contact_name', 'email', 'phone', 'town', 'postcode', 'current_system', 'message', 'assigned_admin_id', 'follow_up_date', 'follow_up_time'] as $key) {
            if ($this->has($key)) {
                $value = trim((string) $this->input($key));
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:160'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:'.TenantRules::PHONE_PATTERN],
            'town' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9 ]{2,10}$/'],
            'shops_count' => ['required', 'integer', 'min:1', 'max:'.Lead::MAX_SHOPS],
            'tills_count' => ['required', 'integer', 'min:1', 'max:'.Lead::MAX_TILLS],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'current_system' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:5000'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'consent_marketing' => ['boolean'],
            'assigned_admin_id' => ['nullable', 'string', Rule::exists('admins', 'id')->where('is_active', true)],
            'follow_up_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'follow_up_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Enter the business name.',
            'contact_name.required' => 'Enter the contact’s name.',
            'email.required_without' => 'Enter an email address or a phone number so we can reach them.',
            'phone.required_without' => 'Enter a phone number or an email address so we can reach them.',
            'email.email' => 'Enter a valid email address.',
            'phone.regex' => 'Enter a phone number like 07700 900123.',
            'postcode.regex' => 'Enter a postcode like LS1 6BX.',
            'shops_count.min' => 'A business has at least 1 shop.',
            'shops_count.max' => 'Up to '.Lead::MAX_SHOPS.' shops.',
            'tills_count.min' => 'They need at least 1 till.',
            'tills_count.max' => 'Up to '.Lead::MAX_TILLS.' tills.',
            'assigned_admin_id.exists' => 'Choose an active member of staff.',
            'follow_up_date.after_or_equal' => 'Choose today or a later date.',
        ];
    }

    public function details(?Lead $existing = null): LeadDetails
    {
        return new LeadDetails(
            businessName: (string) $this->input('business_name'),
            contactName: (string) $this->input('contact_name'),
            email: $this->input('email'),
            phone: $this->input('phone'),
            town: $this->input('town'),
            postcode: $this->input('postcode'),
            shopsCount: $this->integer('shops_count'),
            tillsCount: $this->integer('tills_count'),
            businessType: BusinessType::from((string) $this->input('business_type')),
            currentSystem: $this->input('current_system'),
            message: $this->input('message'),
            source: LeadSource::from((string) $this->input('source')),
            consentMarketing: $this->boolean('consent_marketing'),
            utm: $existing?->utm,
            ip: $existing?->ip,
        );
    }

    public function assignee(): ?Admin
    {
        $id = $this->input('assigned_admin_id');

        return $id === null ? null : Admin::query()->find($id);
    }

    public function followUpAt(): ?Carbon
    {
        return FollowUpTime::from($this->input('follow_up_date'), $this->input('follow_up_time'));
    }
}
