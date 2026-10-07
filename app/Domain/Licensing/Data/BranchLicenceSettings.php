<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Enums\LicenceLengthUnit;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Licensing\Support\PlanFeatures;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A branch's licence settings, the owner's "customer form" (module 1.11, contract §17.16): tills allowed
 * (`maxRegisters`), trial or full, length from a start (`validFrom`…`expiresAt`) and features. Every till key of
 * the branch carries them.
 *
 * - `length` null = the plan's trial on first activation (trial only; a full licence needs a length).
 * - `validFrom` null = each till's first activation.
 * - `features` null = the plan's. Multi-branch is not here: it is the company's (UpdateBranchLimits). Saving
 *   stores null when the list equals the plan's at save time (`followingPlan`), so the branch keeps following it.
 */
final readonly class BranchLicenceSettings
{
    public const MAX_REGISTERS = 999;

    /** Longest length per unit: 10 years. */
    public const MAX_LENGTH = ['days' => 3660, 'months' => 120, 'years' => 10];

    /** @var list<Feature>|null */
    public ?array $features;

    /**
     * @param  iterable<Feature|string>|null  $features
     */
    public function __construct(
        public int $maxRegisters = 1,
        public TokenKind $kind = TokenKind::Trial,
        public ?int $length = null,
        public ?LicenceLengthUnit $lengthUnit = null,
        public ?CarbonImmutable $validFrom = null,
        ?iterable $features = null,
    ) {
        $this->features = $features === null
            ? null
            : array_values(array_filter(Feature::normalise($features), fn (Feature $feature) => $feature !== Feature::MultiBranch));
    }

    public static function of(Branch $branch): self
    {
        return new self(
            maxRegisters: $branch->max_registers,
            kind: $branch->licence_kind,
            length: $branch->licence_length,
            lengthUnit: $branch->licence_length_unit,
            validFrom: $branch->licence_valid_from,
            features: $branch->licence_features,
        );
    }

    public function withMaxRegisters(int $maxRegisters): self
    {
        return new self($maxRegisters, $this->kind, $this->length, $this->lengthUnit, $this->validFrom, $this->features);
    }

    /**
     * @param  iterable<Feature|string>|null  $features
     */
    public function withFeatures(?iterable $features): self
    {
        return new self($this->maxRegisters, $this->kind, $this->length, $this->lengthUnit, $this->validFrom, $features);
    }

    /** These settings with features null (follow the plan) when they equal the plan's. */
    public function followingPlan(?Plan $plan): self
    {
        $features = PlanFeatures::toStore($this->features, $plan);

        return $features === $this->features ? $this : $this->withFeatures($features);
    }

    public function hasLength(): bool
    {
        return $this->length !== null && $this->lengthUnit !== null;
    }

    /** "1 year", "30 days", or null (plan trial). */
    public function lengthLabel(): ?string
    {
        return $this->hasLength() ? $this->lengthUnit?->describe((int) $this->length) : null;
    }

    /** Kind, length and start: what decides the keys' dates. */
    public function sameTerm(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->length === $other->length
            && $this->lengthUnit === $other->lengthUnit
            && $this->validFrom?->getTimestamp() === $other->validFrom?->getTimestamp();
    }

    /**
     * @return list<string>|null
     */
    public function featureValues(): ?array
    {
        return $this->features === null ? null : array_map(fn (Feature $feature) => $feature->value, $this->features);
    }

    /**
     * @throws ValidationException
     */
    public function validate(): void
    {
        $errors = [];

        if ($this->maxRegisters < 1 || $this->maxRegisters > self::MAX_REGISTERS) {
            $errors['max_registers'] = 'Allow between 1 and '.self::MAX_REGISTERS.' tills.';
        }

        if (($this->length === null) !== ($this->lengthUnit === null)) {
            $errors['length'] = 'Enter a length and choose days, months or years.';
        } elseif ($this->lengthUnit !== null && ($this->length < 1 || $this->length > self::MAX_LENGTH[$this->lengthUnit->value])) {
            $errors['length'] = 'Choose a length between 1 '.rtrim($this->lengthUnit->value, 's').' and 10 years.';
        }

        if ($this->kind === TokenKind::Full && ! $this->hasLength()) {
            $errors['length'] = 'A full licence needs a length, for example 1 year.';
        }

        if ($this->validFrom !== null && ! $this->hasLength()) {
            $errors['valid_from'] = 'A start date needs a length.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'max_registers' => $this->maxRegisters,
            'licence_kind' => $this->kind,
            'licence_length' => $this->hasLength() ? $this->length : null,
            'licence_length_unit' => $this->hasLength() ? $this->lengthUnit : null,
            'licence_valid_from' => $this->validFrom,
            'licence_features' => $this->featureValues(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAudit(): array
    {
        return [
            'max_registers' => $this->maxRegisters,
            'kind' => $this->kind->value,
            'length' => $this->lengthLabel(),
            'valid_from' => $this->validFrom?->toIso8601String(),
            'features' => $this->featureValues(),
        ];
    }
}
