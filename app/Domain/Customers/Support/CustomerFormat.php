<?php

namespace App\Domain\Customers\Support;

use App\Domain\TillData\Enums\ConsentChannel;
use App\Domain\TillData\Enums\ConsentSource;
use App\Domain\TillData\Enums\CustomerTransactionType;
use Carbon\CarbonImmutable;

/**
 * Labels for the customer screens, statement and email (module 4.4), in the shop owner's words.
 */
final class CustomerFormat
{
    /** Ledger filter groups on the customer page. */
    public const TYPE_GROUPS = ['account', 'points'];

    public static function typeLabel(?CustomerTransactionType $type): string
    {
        return match ($type) {
            CustomerTransactionType::Charge => 'Account sale',
            CustomerTransactionType::Payment => 'Payment',
            CustomerTransactionType::Refund => 'Refund',
            CustomerTransactionType::PointsEarn => 'Points earned',
            CustomerTransactionType::PointsBurn => 'Points spent',
            CustomerTransactionType::PointsAdjust => 'Points adjusted',
            CustomerTransactionType::PointsExpire => 'Points expired',
            CustomerTransactionType::Opening => 'Opening balance',
            CustomerTransactionType::Advance => 'Paid in advance',
            CustomerTransactionType::AdvanceRefund => 'Advance refunded',
            null => 'Other',
        };
    }

    /**
     * The ledger types of a filter group: "account" moves money, "points" moves points (an opening row can do both).
     *
     * @return list<string>
     */
    public static function typeGroup(string $group): array
    {
        return $group === 'points'
            ? ['pointsEarn', 'pointsBurn', 'pointsAdjust', 'pointsExpire', 'opening']
            : ['charge', 'payment', 'refund', 'opening', 'advance', 'advanceRefund'];
    }

    public static function channelLabel(ConsentChannel $channel): string
    {
        return match ($channel) {
            ConsentChannel::Email => 'Email',
            ConsentChannel::Sms => 'Text message',
            ConsentChannel::WhatsApp => 'WhatsApp',
            ConsentChannel::Post => 'Post',
        };
    }

    public static function sourceLabel(?ConsentSource $source): string
    {
        return match ($source) {
            ConsentSource::Till => 'At the till',
            ConsentSource::Web => 'Online',
            ConsentSource::Form => 'Paper form',
            null => 'Not recorded',
        };
    }

    /** A stored UTC "Y-m-d H:i:s" (or ISO) value as ISO-8601 with Z. */
    public static function iso(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->utc()->toIso8601ZuluString();
    }
}
