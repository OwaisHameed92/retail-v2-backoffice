<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Partners\Support\PartnerKeySecret;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * A new API key for a partner (a partner may hold several, e.g. live and staging; rotate = issue a new one, switch
 * the partner over, revoke the old one). Returns the plain key, which exists only in this reply: only its HMAC and
 * last 4 characters are stored. Audited without the key.
 */
class IssuePartnerKey
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return array{0: PartnerKey, 1: string}
     */
    public function handle(Partner $partner, ?string $name = null, ?string $adminId = null): array
    {
        $plain = PartnerKeySecret::generate();
        $name = trim((string) $name) === '' ? null : mb_substr(trim((string) $name), 0, 80);

        $key = PartnerKey::query()->create([
            'partner_id' => $partner->id,
            'name' => $name,
            'key_hash' => PartnerKeySecret::hash($plain),
            'key_last4' => PartnerKeySecret::last4($plain),
            'created_by_admin_id' => $adminId,
        ]);

        $this->audit->handle('partner.key_issued', $partner, null, null, ['key_id' => $key->id, 'name' => $name, 'ending' => $key->key_last4]);

        return [$key, $plain];
    }
}
