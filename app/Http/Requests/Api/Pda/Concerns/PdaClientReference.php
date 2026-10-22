<?php

namespace App\Http\Requests\Api\Pda\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The handheld's optional `client_reference` (a UUID it makes, idempotency) on the create of a stock document:
 * validated on POST only, lower case; null when not sent. PUT ignores it.
 *
 * @mixin FormRequest
 */
trait PdaClientReference
{
    /**
     * @return array<string, mixed>
     */
    protected function clientReferenceRules(): array
    {
        return $this->isMethod('post') ? ['client_reference' => ['nullable', 'string', 'uuid']] : [];
    }

    public function clientReference(): ?string
    {
        $value = $this->isMethod('post') ? $this->validated('client_reference') : null;

        return is_string($value) && $value !== '' ? mb_strtolower($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->safe()->except('client_reference');
    }
}
