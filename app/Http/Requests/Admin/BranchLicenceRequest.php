<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A branch's licence settings (module 1.11). `licences.manage`, like every licence change.
 */
class BranchLicenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->hasAbility(AdminRole::LICENCES_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return LicenceFormRules::branch();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return LicenceFormRules::messages();
    }

    public function settings(): BranchLicenceSettings
    {
        return LicenceFormRules::settings($this);
    }
}
