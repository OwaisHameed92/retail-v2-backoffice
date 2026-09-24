<?php

namespace App\Http\Requests\Admin\Leads;

use App\Domain\Leads\Models\LeadNote;
use Illuminate\Foundation\Http\FormRequest;

class LeadNoteRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:'.LeadNote::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write a note first.',
            'body.max' => 'Keep notes under '.number_format(LeadNote::MAX_LENGTH).' characters.',
        ];
    }
}
