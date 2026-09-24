<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * Branch code rules shared by AddBranch and UpdateBranch. Runs inside the company's scope.
 */
final class BranchCodes
{
    /**
     * @throws ValidationException
     */
    public static function ensureUnique(string $code, ?Branch $ignore = null): void
    {
        if (preg_match(Branch::CODE_PATTERN, $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Use 2 to 5 capital letters, for example LDS.']);
        }

        // Soft-deleted branches keep their code: receipt numbers must never repeat.
        $taken = Branch::query()->withTrashed()
            ->where('code', $code)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => "Another branch of this business already uses the code {$code}."]);
        }
    }
}
