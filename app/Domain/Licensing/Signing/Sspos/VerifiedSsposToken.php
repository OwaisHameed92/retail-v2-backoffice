<?php

namespace App\Domain\Licensing\Signing\Sspos;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * A licence token whose signature (and signer certificate, if any) has been checked. Dates, binding and
 * limits are NOT checked here: that is the caller's job (licence API, redeem, migrate).
 */
final class VerifiedSsposToken
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        /** The token exactly as received, whitespace removed. */
        public readonly string $token,
        public readonly array $payload,
        public readonly ?SignerCertificate $signerCertificate,
    ) {}

    public function kid(): string
    {
        return (string) $this->payload['kid'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->payload, $key, $default);
    }

    public function licenceId(): ?string
    {
        $id = $this->payload['licenceId'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function source(): ?string
    {
        $source = $this->payload['source'] ?? null;

        return is_string($source) ? $source : null;
    }

    public function kind(): ?TokenKind
    {
        $kind = $this->payload['kind'] ?? null;

        return is_string($kind) ? TokenKind::tryFrom($kind) : null;
    }

    public function date(string $field): ?CarbonImmutable
    {
        $value = $this->payload[$field] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }

    /** Lower-case hex SHA-256 of the token's ASCII bytes (the `tokenSha256` of validate and redeem). */
    public function sha256(): string
    {
        return hash('sha256', $this->token);
    }
}
