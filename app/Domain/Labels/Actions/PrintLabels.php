<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Labels\Support\LabelContent;
use App\Domain\Labels\Support\LabelPdf;
use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Labels\Support\LabelTemplates;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * Shelf labels of one shop as a PDF (gap #6), on a template's stock with its options, or the preview of the same
 * labels (`preview()`). Any of the shop's labels can be printed (waiting or printed before); `$markPrinted` takes the
 * waiting ones off the queue once the PDF is made. Runs in the company scope; other shops' ids are not found.
 */
final class PrintLabels
{
    public function __construct(private readonly LabelPdf $pdf, private readonly UpdateLabelQueue $queue) {}

    /**
     * @param  list<string>  $ids
     */
    public function handle(Branch $branch, array $ids, ?string $templateId, int $skip = 0, bool $markPrinted = false): string
    {
        [$labels, $template] = $this->prepare($branch, $ids, $templateId);
        $pdf = $this->pdf->render($labels, $template['stock'], $template['options'], $skip);

        if ($markPrinted) {
            $waiting = LabelQueueItem::query()->where('branch_id', $branch->id)->whereIn('id', $ids)->where('pending', true)->pluck('id')->all();
            if ($waiting !== []) {
                $this->queue->handle($branch, $waiting, 'printed');
            }
        }

        return $pdf;
    }

    /**
     * What the preview shows: the labels, the template, the stock and where each label lands.
     *
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    public function preview(Branch $branch, array $ids, ?string $templateId, int $skip = 0): array
    {
        [$labels, $template] = $this->prepare($branch, $ids, $templateId);
        $count = array_sum(array_map(fn (array $l) => (int) $l['copies'], $labels));
        $stock = LabelStocks::get($template['stock']);

        return [
            'labels' => $labels,
            'template' => $template,
            'stock' => $stock,
            'count' => $count,
            'pages' => count(LabelStocks::layout($stock['key'], $count, $skip)),
            'skip' => $stock['kind'] === 'a4' ? max(0, min($skip, $stock['perPage'] - 1)) : 0,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{0: list<array<string, mixed>>, 1: array{id: string, name: string, stock: string, branchId: string|null, isDefault: bool, options: array<string, bool>}}
     */
    private function prepare(Branch $branch, array $ids, ?string $templateId): array
    {
        $items = LabelQueueItem::query()->where('branch_id', $branch->id)->whereIn('id', $ids)->orderBy('queued_at')->orderBy('id')->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Choose labels of this shop to print.']);
        }

        return [LabelContent::for($items), LabelTemplates::pick($branch->id, $templateId)];
    }
}
