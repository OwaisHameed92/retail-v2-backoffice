<?php

namespace App\Domain\Shared\Rules;

use App\Domain\Shared\Support\Ulid;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a till-sent id: 26 upper-case Crockford base32 characters.
 *
 * Usage: `'branchId' => ['required', new ValidUlid]`.
 */
final class ValidUlid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Ulid::isValid($value)) {
            $fail('The :attribute must be a 26-character ULID.');
        }
    }
}
