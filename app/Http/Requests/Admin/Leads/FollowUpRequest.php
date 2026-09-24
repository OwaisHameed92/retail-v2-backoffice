<?php

namespace App\Http\Requests\Admin\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * "Set follow-up": a date (today or later) with an optional time and note. No date clears the follow-up.
 */
class FollowUpRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'Choose today or a later date.',
            'date.date_format' => 'Choose a date.',
            'time.date_format' => 'Enter a time like 14:30.',
        ];
    }

    public function at(): ?Carbon
    {
        return FollowUpTime::from($this->input('date'), $this->input('time'));
    }
}
