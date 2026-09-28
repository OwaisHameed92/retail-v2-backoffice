<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A company's multi-branch setting and branches allowed (module 1.11). `licences.manage`.
 */
class BranchLimitsRequest extends FormRequest
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
        return ['multi_branch' => ['required', 'boolean']] + LicenceFormRules::limits();
    }
}
