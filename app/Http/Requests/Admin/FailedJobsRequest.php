<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Retry or delete failed jobs: the uuids of one row or of every row shown (a page holds at most 100).
 */
class FailedJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->hasAbility(AdminRole::AUDIT_VIEW) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuids.required' => 'Choose at least one failed job.',
            'uuids.max' => 'Choose at most 100 failed jobs at a time.',
            'uuids.*.uuid' => 'One of the failed jobs is not valid. Refresh the page and try again.',
        ];
    }

    /**
     * @return list<string>
     */
    public function uuids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('uuids')));
    }
}
