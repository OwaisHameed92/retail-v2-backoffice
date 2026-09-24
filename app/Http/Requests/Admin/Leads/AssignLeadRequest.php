<?php

namespace App\Http\Requests\Admin\Leads;

use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:update,lead.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_id' => ['nullable', 'string', Rule::exists('admins', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['admin_id.exists' => 'Choose an active member of staff.'];
    }

    public function assignee(): ?Admin
    {
        $id = $this->input('admin_id');

        return is_string($id) && $id !== '' ? Admin::query()->find($id) : null;
    }
}
