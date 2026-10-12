<?php

namespace App\Domain\Partners\Data;

use App\Domain\Shared\Support\Money;

/**
 * A partner's validated tenant request body (docs/partner-api.md), in one canonical shape: every member present
 * (null when not sent), money as normalised strings, `vatApplies` true unless sent false. Two bodies that mean the
 * same thing have the same `hash()`, so a repeated PUT is recognised as "same" whatever its formatting.
 */
final readonly class TenantRequestInput
{
    /**
     * @param  array<string, mixed>  $payload  The canonical body (camelCase, as the partner sends it).
     */
    private function __construct(public array $payload) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $part = fn (string $name): array => is_array($validated[$name] ?? null) ? $validated[$name] : [];
        $text = fn (array $values, string $key): ?string => isset($values[$key]) && is_string($values[$key]) && trim($values[$key]) !== '' ? trim($values[$key]) : null;
        $money = fn (mixed $value): ?string => is_string($value) && $value !== '' ? Money::normalise($value) : null;

        $seller = $part('seller');
        $business = $part('business');
        $owner = $part('owner');
        $shops = array_values(array_filter($part('shops'), 'is_array'));

        return new self([
            'seller' => ['name' => (string) $text($seller, 'name'), 'email' => (string) $text($seller, 'email')],
            'business' => [
                'name' => (string) $text($business, 'name'),
                'legalName' => $text($business, 'legalName'),
                'companyNumber' => $text($business, 'companyNumber'),
                'vatNumber' => $text($business, 'vatNumber'),
                'phone' => $text($business, 'phone'),
                'email' => $text($business, 'email'),
            ],
            'owner' => ['name' => (string) $text($owner, 'name'), 'email' => (string) $text($owner, 'email'), 'phone' => $text($owner, 'phone')],
            'shops' => array_map(fn (array $shop) => [
                'name' => (string) $text($shop, 'name'),
                'address' => $text($shop, 'address'),
                'town' => $text($shop, 'town'),
                'postcode' => $text($shop, 'postcode'),
                'tills' => (int) ($shop['tills'] ?? 1),
            ], $shops),
            'plan' => (string) ($validated['plan'] ?? ''),
            'setupFeePerTill' => $money($validated['setupFeePerTill'] ?? null),
            'vatApplies' => ! array_key_exists('vatApplies', $validated) || $validated['vatApplies'] === null || filter_var($validated['vatApplies'], FILTER_VALIDATE_BOOLEAN),
            'salePrice' => $money($validated['salePrice'] ?? null),
            'signedOn' => is_string($validated['signedOn'] ?? null) ? $validated['signedOn'] : null,
            'notes' => is_string($validated['notes'] ?? null) && trim($validated['notes']) !== '' ? trim($validated['notes']) : null,
        ]);
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function businessName(): string
    {
        return mb_substr((string) ($this->payload['business']['name'] ?? ''), 0, 160);
    }

    public function sellerName(): string
    {
        return mb_substr((string) ($this->payload['seller']['name'] ?? ''), 0, 120);
    }

    public function sellerEmail(): string
    {
        return mb_strtolower(mb_substr((string) ($this->payload['seller']['email'] ?? ''), 0, 255));
    }
}
