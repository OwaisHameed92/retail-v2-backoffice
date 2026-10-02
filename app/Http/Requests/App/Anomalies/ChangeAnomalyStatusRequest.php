<?php

namespace App\Http\Requests\App\Anomalies;

use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Acknowledge, dismiss (with a reason) or reopen a finding (module 6.6): owners and managers only.
 */
class ChangeAnomalyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AnomalyVisibility::canManage(app(CurrentCompany::class)->role());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AnomalyStatus::class)],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->input('status') === AnomalyStatus::Dismissed->value)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Say why you are dismissing it.'];
    }

    public function status(): AnomalyStatus
    {
        return AnomalyStatus::from((string) $this->validated('status'));
    }
}
