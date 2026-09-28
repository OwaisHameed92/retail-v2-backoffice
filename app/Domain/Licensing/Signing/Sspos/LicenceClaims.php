<?php

namespace App\Domain\Licensing\Signing\Sspos;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The business fields of a portal licence token (contract v1.3.1 §17.2, licence-token-payload.schema.json).
 * `SsposTokenSigner` adds `v`, `kid` and `signerCert`. Optional fields are omitted when empty, in the field
 * order of the contract samples, so the worked example reproduces byte for byte.
 *
 * Issuer and online-check policy default to config('licence.token.*').
 */
final class LicenceClaims
{
    public const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public const INSTALL_CODE = '/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    public const FEATURE = '/^[a-z0-9]+([._-][a-z0-9]+)*$/';

    /** licenceCompany fields in the order the samples write them, with their maximum lengths. */
    public const COMPANY_FIELDS = [
        'businessType' => 40, 'address' => 200, 'town' => 80, 'postcode' => 10, 'phone' => 30,
        'email' => 120, 'vatNumber' => 20, 'ownerName' => 80, 'receiptFooter' => 200,
    ];

    /**
     * Array inputs are checked at runtime (they come from forms and models), hence the loose types.
     *
     * @param  array<mixed>  $features  Till feature names (snake_case), e.g. multi_branch.
     * @param  array<mixed>  $limits  Name => whole number, e.g. ['branches' => 2].
     * @param  array<mixed>  $company  Any of COMPANY_FIELDS => string|null; empty values are dropped.
     * @param  array<mixed>|null  $onlineCheck  {required: bool, intervalHours?: int, graceDays?: int}; null = config.
     */
    public function __construct(
        public readonly string $licenceId,
        public readonly TokenKind $kind,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $businessName,
        public readonly string $branchName,
        public readonly int $maxRegisters,
        public readonly CarbonImmutable $issuedAt,
        public readonly CarbonImmutable $validFrom,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $installCode = null,
        public readonly array $features = [],
        public readonly array $limits = [],
        public readonly ?string $notes = null,
        public readonly array $company = [],
        public readonly ?string $issuer = null,
        public readonly ?array $onlineCheck = null,
    ) {
        $this->validate();
    }

    /**
     * The full payload in contract order. `$signerCert` null = omitted (uncertified dev keys only).
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $kid, ?string $signerCert): array
    {
        $payload = [
            'v' => 1,
            'kid' => $kid,
            'licenceId' => $this->licenceId,
            'kind' => $this->kind->value,
            'source' => 'portal',
            'issuer' => $this->issuer ?? (string) config('licence.token.issuer'),
            'companyId' => $this->companyId,
            'branchId' => $this->branchId,
            'businessName' => $this->businessName,
            'branchName' => $this->branchName,
            'installCode' => $this->installCode,
            'maxRegisters' => $this->maxRegisters,
            'features' => $this->features,
            'issuedAt' => self::utc($this->issuedAt),
            'validFrom' => self::utc($this->validFrom),
            'expiresAt' => self::utc($this->expiresAt),
            'onlineCheck' => $this->onlineCheck ?? self::configuredOnlineCheck(),
            'limits' => $this->limits,
            'notes' => $this->notes,
            'company' => $this->companyBlock(),
            'signerCert' => $signerCert,
        ];

        return array_filter($payload, fn (mixed $value) => $value !== null && $value !== '' && $value !== []);
    }

    public static function utc(CarbonInterface $date): string
    {
        return $date->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{required: bool, intervalHours: int, graceDays: int}
     */
    public static function configuredOnlineCheck(): array
    {
        return [
            'required' => (bool) config('licence.token.online_check.required', true),
            'intervalHours' => (int) config('licence.token.online_check.interval_hours', 24),
            'graceDays' => (int) config('licence.token.online_check.grace_days', 14),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyBlock(): array
    {
        $block = [];

        foreach (array_keys(self::COMPANY_FIELDS) as $field) {
            $value = $this->company[$field] ?? null;

            if ($value !== null && $value !== '') {
                $block[$field] = $value;
            }
        }

        return $block;
    }

    private function validate(): void
    {
        foreach (['licenceId' => $this->licenceId, 'companyId' => $this->companyId, 'branchId' => $this->branchId] as $field => $id) {
            self::require(preg_match(self::ULID, $id) === 1, "{$field} must be an upper-case ULID.");
        }

        self::require($this->installCode === null || $this->installCode === '' || preg_match(self::INSTALL_CODE, $this->installCode) === 1, 'installCode must look like XXXX-XXXX.');
        self::require(mb_strlen($this->businessName) <= 100 && mb_strlen($this->branchName) <= 100, 'businessName and branchName are at most 100 characters.');
        self::require($this->issuer === null || mb_strlen($this->issuer) <= 80, 'issuer is at most 80 characters.');
        self::require($this->maxRegisters >= 1 && $this->maxRegisters <= 999, 'maxRegisters must be 1 to 999.');
        self::require($this->notes === null || mb_strlen($this->notes) <= 200, 'notes is at most 200 characters.');
        self::require($this->expiresAt->greaterThan($this->validFrom), 'expiresAt must be after validFrom.');

        self::require(array_is_list($this->features) && count(array_unique($this->features)) === count($this->features), 'features must be a list without duplicates.');
        foreach ($this->features as $feature) {
            self::require(is_string($feature) && preg_match(self::FEATURE, $feature) === 1, 'Invalid feature name (lower case, e.g. multi_branch).');
        }

        foreach ($this->limits as $name => $value) {
            self::require(is_string($name) && $name !== '' && is_int($value), 'limits must map names to whole numbers.');
        }

        foreach ($this->company as $field => $value) {
            self::require(array_key_exists($field, self::COMPANY_FIELDS), "Unknown company field {$field}.");
            self::require($value === null || (is_string($value) && mb_strlen($value) <= self::COMPANY_FIELDS[$field]), "company.{$field} is too long.");
        }

        $check = $this->onlineCheck;
        self::require($check === null || is_bool($check['required'] ?? null), 'onlineCheck.required must be a boolean.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException("Licence claims: {$message}");
        }
    }
}
