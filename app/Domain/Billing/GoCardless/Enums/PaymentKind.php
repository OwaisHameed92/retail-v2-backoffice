<?php

namespace App\Domain\Billing\GoCardless\Enums;

/**
 * What a GoCardless payment collects: a subscription period, or the setup fee (or one instalment of it).
 */
enum PaymentKind: string
{
    case Subscription = 'subscription';
    case SetupFee = 'setupFee';
}
