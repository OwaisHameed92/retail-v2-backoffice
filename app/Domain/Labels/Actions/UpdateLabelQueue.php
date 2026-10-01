<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes queued labels of one shop (gap #6): mark them printed (they leave the queue and keep `printed_at`, and the
 * next price change queues them again), take them off the queue without printing, or set how many copies to print.
 * Ids of another shop or business are not found. Runs in the company scope. Returns how many rows changed.
 */
final class UpdateLabelQueue
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  list<string>  $ids
     * @param  'printed'|'remove'|'copies'  $change
     */
    public function handle(Branch $branch, array $ids, string $change, int $copies = 1): int
    {
        if ($change === 'copies' && ($copies < 1 || $copies > 99)) {
            throw ValidationException::withMessages(['copies' => 'Print 1 to 99 copies.']);
        }

        return DB::transaction(function () use ($branch, $ids, $change, $copies) {
            /** @var Collection<int, LabelQueueItem> $items */
            $items = LabelQueueItem::query()->where('branch_id', $branch->id)->whereIn('id', $ids)->where('pending', true)->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['ids' => 'Those labels are no longer waiting in this shop. Refresh the page.']);
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $userId = auth('web')->id();

            foreach ($items as $item) {
                $item->forceFill(match ($change) {
                    'printed' => ['pending' => false, 'printed_at' => $now, 'printed_by_user_id' => $userId],
                    'remove' => ['pending' => false],
                    'copies' => ['copies' => $copies],
                })->save();
            }

            if ($change !== 'copies') {
                $this->audit->handle($change === 'printed' ? 'labels.printed' : 'labels.removed', $branch, null, ['count' => $items->count()], ['shop' => $branch->name]);
            }

            return $items->count();
        });
    }
}
