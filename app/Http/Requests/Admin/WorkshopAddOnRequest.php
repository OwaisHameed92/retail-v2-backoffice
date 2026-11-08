<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * W5: the licence tick for `workshop_integration` on one shop (`on`: true adds it, false takes it off). Access is the
 * route's `can:manage` (WorkshopConnectionPolicy).
 */
class WorkshopAddOnRequest extends FormRequest
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
        return ['on' => ['required', 'boolean']];
    }
}
