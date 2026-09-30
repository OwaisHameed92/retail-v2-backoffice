<?php

namespace App\Domain\Setup\Support;

use App\Domain\TillData\Models\PaymentType;
use Illuminate\Validation\ValidationException;

/**
 * The tills always need one active cash payment type (to take cash and give change): refuses switching off or
 * removing the last one. Call inside the company scope.
 */
final class KeepCashTender
{
    /**
     * @throws ValidationException
     */
    public static function check(PaymentType $type, string $field = 'status'): void
    {
        $others = PaymentType::query()->whereKeyNot($type->id)->where('is_cash', true)->where('is_active', true)->exists();

        if (! $others) {
            throw ValidationException::withMessages([$field => "{$type->name} is the only active cash payment type. The tills need one to take cash."]);
        }
    }
}
