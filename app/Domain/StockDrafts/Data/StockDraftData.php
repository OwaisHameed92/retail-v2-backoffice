<?php

namespace App\Domain\StockDrafts\Data;

use App\Domain\StockDrafts\Enums\StockDraftKind;

/**
 * What SaveStockDraft writes: the kind, the header's columns (already checked by the caller: product, order, supplier,
 * quantities) and its lines (`stock_draft_lines` columns, in order). `clientReference` is the handheld's
 * idempotency UUID (create only); `enteredBy` the name the till shows ("Stock room 1 (handheld)").
 */
final readonly class StockDraftData
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public StockDraftKind $kind,
        public array $header,
        public array $lines = [],
        public ?string $clientReference = null,
        public ?string $handheldUserId = null,
        public string $enteredBy = '',
    ) {}
}
