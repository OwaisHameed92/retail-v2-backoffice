<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * What RecordCardPayment made of a DNA callback (OS-9), and the HTTP status DNA is answered with: 200 once the
 * result is verified and handled (also a repeat, so DNA stops retrying), 403 for a bad signature, 404 for an unknown
 * store or order, 422 for a success whose amount or currency is not the order's.
 */
enum CardPaymentOutcome: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Duplicate = 'duplicate';
    case BadSignature = 'badSignature';
    case UnknownStore = 'unknownStore';
    case UnknownOrder = 'unknownOrder';
    case AmountMismatch = 'amountMismatch';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Paid, self::Failed, self::Duplicate => 200,
            self::BadSignature => 403,
            self::UnknownStore, self::UnknownOrder => 404,
            self::AmountMismatch => 422,
        };
    }
}
