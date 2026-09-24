<?php

namespace App\Domain\Licensing\Signing;

use Carbon\CarbonImmutable;

/**
 * A licence token whose signature, kid and issuer have been checked. Business checks (validUntil, status,
 * deviceId) are still the caller's job.
 */
final class VerifiedToken
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        private readonly array $header,
        private readonly array $claims,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    public function claim(string $name, mixed $default = null): mixed
    {
        return $this->claims[$name] ?? $default;
    }

    public function kid(): string
    {
        return (string) $this->header['kid'];
    }

    public function jti(): ?string
    {
        return is_string($this->claims['jti'] ?? null) ? $this->claims['jti'] : null;
    }

    /** `iat` as UTC time, or null when missing or not an integer. */
    public function issuedAt(): ?CarbonImmutable
    {
        $iat = $this->claims['iat'] ?? null;

        return is_int($iat) ? CarbonImmutable::createFromTimestampUTC($iat) : null;
    }
}
