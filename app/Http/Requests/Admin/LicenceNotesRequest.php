<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff notes on a licence. Authorised by the `can:licences.manage` route middleware.
 */
class LicenceNotesRequest extends FormRequest
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
        return ['notes' => ['nullable', 'string', 'max:5000']];
    }
}
