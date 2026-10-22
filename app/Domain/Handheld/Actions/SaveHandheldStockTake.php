<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldStockGuard;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Actions\SaveStockDraft;
use App\Domain\StockDrafts\Data\StockDraftData;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/stock-takes` and `PUT …/{id}` (PDA_PHASE2_REQUEST §1a): a shelf count for the user's shop, drafted
 * as the till's own `StockTake` with `origin: headOffice` (contract proposal pda-stock-drafts §5.2, number
 * HS-{shop}-000001). Each line is a product (once) and what is on the shelf in base units (0 allowed); the till works
 * the variance out against its stock when it applies the count. "can adjust stock" only; every product of the
 * business, not archived, tracking stock (the line is named in `errors`). A PUT replaces the lines while the portal
 * owns the count (409 STOCK_TAKE_OWNED_BY_TILL / STOCK_TAKE_CANCELLED after).
 */
final class SaveHandheldStockTake
{
    public function __construct(private readonly SaveStockDraft $save) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: StockDraft, 1: bool}
     *
     * @throws ApiException|ValidationException
     */
    public function create(HandheldUser $user, Branch $shop, array $input, ?string $clientReference): array
    {
        HandheldStockGuard::assertMayAdjust($user);
        $existing = SaveStockDraft::existing($shop, StockDraftKind::StockTake, $clientReference);

        return $existing !== null ? [$existing, false] : $this->save->create($shop, $this->data($user, $input, $clientReference));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    public function update(HandheldUser $user, StockDraft $draft, array $input): StockDraft
    {
        HandheldStockGuard::assertMayAdjust($user);
        HandheldStockGuard::unlocked(fn () => StockDraftLocked::unlessPending($draft));
        $data = $this->data($user, $input, null);

        return HandheldStockGuard::unlocked(fn () => $this->save->update($draft, $data));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    private function data(HandheldUser $user, array $input, ?string $clientReference): StockDraftData
    {
        $lines = [];

        foreach (array_values((array) $input['lines']) as $i => $line) {
            $product = HandheldStockGuard::product((string) $line['product_id'], "lines.{$i}.product_id");
            $lines[] = ['product_id' => $product->id, 'product_name' => mb_substr($product->name, 0, 255), 'quantity' => Money::normalise($line['counted_quantity'], 4)];
        }

        return new StockDraftData(StockDraftKind::StockTake, [
            'name' => mb_substr(trim((string) $input['name']), 0, 120),
            'note' => is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null,
            'happened_at' => now('UTC'),
        ], $lines, $clientReference, $user->id, HandheldStockGuard::enteredBy($user));
    }
}
