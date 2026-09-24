<?php

namespace App\Http\Requests\Admin;

use App\Domain\Plans\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pick an active plan for a licence, or as a company's plan for new tills (`apply_to_licences` also moves the
 * company's current licences). Authorised by the `can:licences.manage` route middleware.
 */
class ChangeLicencePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'apply_to_licences' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Choose a plan.',
            'plan_id.exists' => 'Choose an active plan.',
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->findOrFail((string) $this->input('plan_id'));
    }
}
