<?php

namespace App\Http\Requests\Admin\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * "Mark contacted": an optional note on the call and an optional next follow-up.
 */
class ContactedRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
            'next_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'next_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'next_date.after_or_equal' => 'Choose today or a later date.',
            'next_time.date_format' => 'Enter a time like 14:30.',
        ];
    }

    public function nextFollowUpAt(): ?Carbon
    {
        return FollowUpTime::from($this->input('next_date'), $this->input('next_time'));
    }
}
