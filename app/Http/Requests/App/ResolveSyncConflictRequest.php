<?php

namespace App\Http\Requests\App;

use App\Domain\TillData\Sync\Enums\ConflictResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settling a sync conflict on the tenant portal (module 2.9B). Route: `company.can:sync.manage`.
 */
class ResolveSyncConflictRequest extends FormRequest
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
            'resolution' => ['required', Rule::enum(ConflictResolution::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['resolution.required' => 'Choose how to settle this conflict.'];
    }

    public function resolution(): ConflictResolution
    {
        return ConflictResolution::from((string) $this->input('resolution'));
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) ? $note : null;
    }
}
