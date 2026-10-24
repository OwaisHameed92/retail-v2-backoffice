<?php

namespace App\Domain\OnlineStore\Payments;

use App\Domain\OnlineStore\Models\OnlineStore;
use SensitiveParameter;

/**
 * A store's own DNA Payments account (OS-9): each business is its own merchant, so the money goes straight to it.
 * Read from the store's encrypted columns; null until the client id, secret and terminal id are all saved. Never
 * logged, serialised or sent to the browser (`__debugInfo` hides the secret).
 */
final readonly class DnaCredentials
{
    public function __construct(
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        public string $terminalId,
        public bool $testMode,
    ) {}

    public static function fromStore(OnlineStore $store): ?self
    {
        $id = trim((string) $store->dna_client_id);
        $secret = trim((string) $store->dna_client_secret);
        $terminal = trim((string) $store->dna_terminal_id);

        return $id === '' || $secret === '' || $terminal === '' ? null : new self($id, $secret, $terminal, (bool) $store->dna_test_mode);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['clientId' => self::last4($this->clientId), 'clientSecret' => '****', 'terminalId' => self::last4($this->terminalId), 'testMode' => $this->testMode];
    }

    /** "••••1234": how the portal shows a saved credential. */
    public static function last4(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : '••••'.mb_substr($value, -4);
    }
}
