<?php

namespace App\Domain\PortalUsers\Enums;

/**
 * Where a portal invitation stands (module 4.1). Derived from its timestamps, never stored.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Revoked = 'revoked';

    /** Still worth showing on the Invitations tab: it can be resent or revoked. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Expired;
    }
}
