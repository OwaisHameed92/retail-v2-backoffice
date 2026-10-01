<?php

namespace App\Http\Requests\Admin\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/** A master catalogue CSV upload: the file (up to 200 MB) and where it came from. Route: `can:catalogue.manage`. */
class MasterImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:204800'],
            'source_ref' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['file.mimes' => 'Upload a CSV file.', 'file.max' => 'The file can be up to 200 MB. Split a larger file.'];
    }
}
