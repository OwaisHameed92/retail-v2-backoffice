<?php

namespace App\Http\Requests\Admin;

use App\Domain\Licensing\Actions\UpdateBranchLimits;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Enums\LicenceLengthUnit;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Plans\Enums\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules and input for the licence form (module 1.11): a branch's settings (`max_registers`, `kind`, `length`,
 * `length_unit`, `valid_from` as a UK date, `features`) and the company's branch limits (`multi_branch`,
 * `max_branches`). Shared by the branch licence dialog, the tenant wizard and lead approval.
 */
final class LicenceFormRules
{
    /**
     * @return array<string, mixed>
     */
    public static function branch(bool $maxRegistersRequired = true): array
    {
        return [
            'max_registers' => [$maxRegistersRequired ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.BranchLicenceSettings::MAX_REGISTERS],
            'kind' => ['required', Rule::enum(TokenKind::class)],
            'length' => ['nullable', 'integer', 'min:1', 'max:3660', 'required_with:length_unit'],
            'length_unit' => ['nullable', Rule::enum(LicenceLengthUnit::class), 'required_with:length'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_values(array_diff(Feature::values(), [Feature::MultiBranch->value])))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limits(): array
    {
        return [
            'multi_branch' => ['boolean'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:'.UpdateBranchLimits::MAX_BRANCHES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'max_registers.required' => 'Enter how many tills this branch may run.',
            'max_registers.min' => 'Allow at least 1 till.',
            'length.required_with' => 'Enter a length, for example 12.',
            'length_unit.required_with' => 'Choose days, months or years.',
            'valid_from.date_format' => 'Enter a start date.',
            'features.*.in' => 'Choose features from the list.',
        ];
    }

    public static function settings(FormRequest $request, int $defaultMaxRegisters = 1): BranchLicenceSettings
    {
        $validFrom = $request->input('valid_from');
        $length = $request->input('length');

        return new BranchLicenceSettings(
            maxRegisters: $request->filled('max_registers') ? $request->integer('max_registers') : $defaultMaxRegisters,
            kind: TokenKind::from((string) $request->input('kind', TokenKind::Trial->value)),
            length: $length === null || $length === '' ? null : (int) $length,
            lengthUnit: LicenceLengthUnit::tryFrom((string) $request->input('length_unit')),
            validFrom: is_string($validFrom) && $validFrom !== '' ? CarbonImmutable::parse($validFrom, 'Europe/London')->startOfDay()->utc() : null,
            features: is_array($request->input('features')) ? array_values(array_filter($request->input('features'), 'is_string')) : null,
        );
    }
}
