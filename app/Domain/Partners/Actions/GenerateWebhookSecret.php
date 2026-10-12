<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Support\PartnerKeySecret;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * A new webhook signing secret for a partner (first one or a rotation): stored encrypted, returned once for staff to
 * give the partner. From now on every webhook is signed with it (the old one stops at once). Audited without it.
 */
class GenerateWebhookSecret
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Partner $partner): string
    {
        $secret = PartnerKeySecret::generate(PartnerKeySecret::SECRET_PREFIX);
        $rotated = $partner->webhook_secret !== null;

        $partner->webhook_secret = $secret;
        $partner->webhook_secret_last4 = substr($secret, -4);
        $partner->save();

        $this->audit->handle($rotated ? 'partner.webhook_secret_rotated' : 'partner.webhook_secret_created', $partner, null, null, ['ending' => $partner->webhook_secret_last4]);

        return $secret;
    }
}
