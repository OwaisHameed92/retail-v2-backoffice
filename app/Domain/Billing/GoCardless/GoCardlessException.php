<?php

namespace App\Domain\Billing\GoCardless;

use RuntimeException;

/**
 * GoCardless is not configured, refused a request or could not be reached. The message is safe to show staff
 * (it never holds the access token).
 */
class GoCardlessException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('GoCardless is not set up yet: add GOCARDLESS_ACCESS_TOKEN (and the webhook secret) to the server settings.');
    }
}
