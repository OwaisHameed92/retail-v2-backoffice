<?php

namespace App\Http\Requests\App\Privacy;

use App\Domain\Privacy\Models\PrivacySettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The data retention setting (module 7.7): months after a customer's last activity (blank = keep until asked),
 * between PrivacySettings::MIN_MONTHS and MAX_MONTHS, and whether the daily run anonymises. The route checks
 * `privacy.manage`.
 */
class PrivacySettingsRequest extends FormRequest
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
            'retention_months' => ['nullable', 'integer', 'min:'.PrivacySettings::MIN_MONTHS, 'max:'.PrivacySettings::MAX_MONTHS],
            'auto_anonymise' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retention_months.min' => 'Keep customer details for at least '.PrivacySettings::MIN_MONTHS.' months.',
            'retention_months.max' => 'Choose at most '.PrivacySettings::MAX_MONTHS.' months (10 years).',
        ];
    }

    public function months(): ?int
    {
        $value = $this->validated('retention_months');

        return $value === null ? null : (int) $value;
    }
}
