<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldStockGuard;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\StockDrafts\Actions\CancelStockDraft;
use App\Domain\StockDrafts\Models\StockDraft;

/**
 * `POST /api/pda/stock-adjustments/{id}/cancel`, `stock-takes/{id}/cancel`, `purchase-returns/{id}/cancel`
 * (PDA_PHASE2_REQUEST §1): withdraws a stock draft while the portal still owns it (the till never saw it). "can
 * adjust stock" only; once the till has it → 409 …_OWNED_BY_TILL, already cancelled → 409 …_CANCELLED.
 */
final class CancelHandheldStockDraft
{
    public function __construct(private readonly CancelStockDraft $cancel) {}

    /**
     * @throws ApiException
     */
    public function handle(HandheldUser $user, StockDraft $draft, ?string $reason): StockDraft
    {
        HandheldStockGuard::assertMayAdjust($user);

        return HandheldStockGuard::unlocked(fn () => $this->cancel->handle($draft, $reason));
    }
}
