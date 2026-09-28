<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Enums\SigningKeyStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use LogicException;

/**
 * One Ed25519 licence signing key. The secret key is only present on the active key (it is wiped from the
 * database on retirement) and is never part of toArray(), JSON, var_dump/print_r or serialize().
 *
 * @implements Arrayable<string, string|null>
 */
final class SigningKey implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $kid,
        /** Raw 32-byte Ed25519 public key. */
        public readonly string $publicKey,
        /** Raw 64-byte libsodium secret key, or null for retired keys. */
        #[\SensitiveParameter] private readonly ?string $secretKey,
        public readonly CarbonImmutable $createdAt,
        public readonly ?CarbonImmutable $retiredAt = null,
        /** The owner's signer certificate for this key (SSPOSCERT1…, contract §17.17). Not secret. */
        public readonly ?string $signerCert = null,
    ) {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Ed25519 public key must be 32 bytes.');
        }

        if ($secretKey !== null && strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ed25519 secret key must be 64 bytes.');
        }
    }

    public function canSign(): bool
    {
        return $this->secretKey !== null && $this->retiredAt === null;
    }

    /**
     * Only for LicenceTokenSigner. Never log, display or return this value.
     */
    public function secretKey(): string
    {
        if (! $this->canSign() || $this->secretKey === null) {
            throw new LogicException("Signing key {$this->kid} cannot sign (retired or no secret key).");
        }

        return $this->secretKey;
    }

    /** Public key as base64url without padding: the JWK `x` value. */
    public function x(): string
    {
        return Base64Url::encode($this->publicKey);
    }

    /** When a retired key stops verifying; null while active. */
    public function expiresAt(int $keepDays): ?CarbonImmutable
    {
        return $this->retiredAt?->addDays($keepDays);
    }

    public function status(CarbonInterface $now, int $keepDays): SigningKeyStatus
    {
        $expiresAt = $this->expiresAt($keepDays);

        return match (true) {
            $expiresAt === null => SigningKeyStatus::Active,
            $expiresAt->lessThanOrEqualTo($now) => SigningKeyStatus::Expired,
            default => SigningKeyStatus::Retired,
        };
    }

    /**
     * Public view only: kid, public key and dates. Never the secret.
     *
     * @return array{kid: string, publicKey: string, createdAt: string, retiredAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'kid' => $this->kid,
            'publicKey' => $this->x(),
            'createdAt' => $this->createdAt->utc()->toIso8601ZuluString(),
            'retiredAt' => $this->retiredAt?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{kid: string, publicKey: string, createdAt: string, retiredAt: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array{kid: string, publicKey: string, createdAt: string, retiredAt: string|null}
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Refuse serialize() so the secret can never end up in a cache store, queue payload or session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('SigningKey must not be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('SigningKey must not be unserialized.');
    }
}
