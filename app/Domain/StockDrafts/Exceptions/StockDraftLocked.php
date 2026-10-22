<?php

namespace App\Domain\StockDrafts\Exceptions;

use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Models\StockDraft;
use RuntimeException;

/**
 * A stock draft the portal may no longer change or cancel: the shop's till has it (sent in a pull, or its outcome
 * pushed), or it was cancelled. The caller words it (the handheld: 409 …_OWNED_BY_TILL / …_CANCELLED).
 */
final class StockDraftLocked extends RuntimeException
{
    public function __construct(public readonly StockDraft $draft)
    {
        parent::__construct(self::reason($draft));
    }

    public static function unlessPending(StockDraft $draft): void
    {
        if ($draft->state !== StockDraftState::Pending) {
            throw new self($draft);
        }
    }

    public function cancelled(): bool
    {
        return $this->draft->state === StockDraftState::Cancelled;
    }

    /** Why the portal may not change it, or null while it may. */
    public static function reason(StockDraft $draft): ?string
    {
        return match ($draft->state) {
            StockDraftState::Pending => null,
            StockDraftState::WithTill => 'The shop’s till has this now and applies it at its sync. Change it on the till, or make a new one.',
            StockDraftState::Confirmed => 'The shop’s till has applied this. Make a new one to change the stock again.',
            StockDraftState::Rejected => 'The shop’s till refused this: '.($draft->reject_reason ?: 'no reason given').'. Make a new one instead.',
            StockDraftState::Cancelled => 'This was cancelled. Make a new one instead.',
        };
    }
}
