<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Validation\ValidationException;

/**
 * Register code rules: two digits "01".."99", unique per branch, never reused (soft-deleted rows count).
 */
final class RegisterCodes
{
    /**
     * @throws ValidationException
     */
    public static function ensureUnique(Branch $branch, string $code, ?Register $ignore = null): void
    {
        if (preg_match(Register::CODE_PATTERN, $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Use two digits from 01 to 99.']);
        }

        $taken = Register::query()->withTrashed()
            ->where('branch_id', $branch->getKey())
            ->where('code', $code)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => "Till {$code} already exists in {$branch->name}."]);
        }
    }

    /**
     * The lowest free code in the branch.
     *
     * @throws ValidationException when all 99 codes are used
     */
    public static function next(Branch $branch): string
    {
        $used = Register::query()->withTrashed()->where('branch_id', $branch->getKey())->pluck('code')->all();

        for ($number = 1; $number <= Register::MAX_PER_BRANCH; $number++) {
            $code = Register::codeFor($number);

            if (! in_array($code, $used, true)) {
                return $code;
            }
        }

        throw ValidationException::withMessages(['code' => "{$branch->name} already has the maximum of ".Register::MAX_PER_BRANCH.' tills.']);
    }
}
