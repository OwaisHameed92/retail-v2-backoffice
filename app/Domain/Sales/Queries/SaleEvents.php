<?php

namespace App\Domain\Sales\Queries;

use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\TillAuditLog;
use Carbon\CarbonImmutable;

/**
 * The till's audit rows about one sale (module 4.6): rows whose entity is this sale (RefundApproved, voids…), and
 * the lines voided while the basket was open. A `LineVoided` row names the till's cart id, not the sale id
 * (PORTAL-CHANGES-0.1.15 item 16), so those are matched by till and time: after the till's previous sale finished
 * (at most 12 hours before) and up to when this one finished.
 */
final class SaleEvents
{
    private const LIMIT = 50;

    private const LOOK_BACK_HOURS = 12;

    /**
     * @return list<array{id: string, action: string, at: string|null, userId: string|null, reason: string|null, details: list<array{label: string, value: string}>, matchedByTime: bool}>
     */
    public static function for(Sale $sale): array
    {
        $own = TillAuditLog::query()->where('entity_name', 'Sale')->where('entity_id', $sale->id)
            ->orderBy('at')->limit(self::LIMIT)->get();

        $end = $sale->completed_at ?? $sale->updated_at;
        $voided = collect();

        if ($end !== null && $sale->register_id !== null) {
            $start = self::basketStart($sale, $end);
            $voided = TillAuditLog::query()->where('branch_id', $sale->branch_id)->where('register_id', $sale->register_id)
                ->where('action', 'LineVoided')
                ->where('at', '>', $start->format('Y-m-d H:i:s'))->where('at', '<=', $end->format('Y-m-d H:i:s'))
                ->orderBy('at')->limit(self::LIMIT)->get();
        }

        return $own->map(fn (TillAuditLog $log) => self::row($log, false))
            ->concat($voided->reject(fn (TillAuditLog $log) => $own->contains('id', $log->id))->map(fn (TillAuditLog $log) => self::row($log, true)))
            ->sortBy('at')->values()->all();
    }

    private static function basketStart(Sale $sale, CarbonImmutable $end): CarbonImmutable
    {
        $floor = $end->subHours(self::LOOK_BACK_HOURS);
        $previous = Sale::query()->where('register_id', $sale->register_id)->where('number', '<', $sale->number)
            ->orderByDesc('number')->first(['id', 'completed_at', 'updated_at']);
        $previousEnd = $previous === null ? null : ($previous->completed_at ?? $previous->updated_at);

        return $previousEnd !== null && $previousEnd->gt($floor) && $previousEnd->lt($end) ? $previousEnd : $floor;
    }

    /**
     * @return array{id: string, action: string, at: string|null, userId: string|null, reason: string|null, details: list<array{label: string, value: string}>, matchedByTime: bool}
     */
    private static function row(TillAuditLog $log, bool $matchedByTime): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'at' => $log->at->toIso8601ZuluString(),
            'userId' => $log->user_id !== '' ? $log->user_id : null,
            'reason' => $log->reason !== '' ? $log->reason : null,
            'details' => self::details($log->after_json),
            'matchedByTime' => $matchedByTime,
        ];
    }

    /**
     * Up to 8 plain values of the row's afterJson ("product: Coca-Cola", "quantity: 1"), as text.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function details(?string $json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        if (! is_array($data)) {
            return [];
        }

        $out = [];

        foreach ($data as $key => $value) {
            if (count($out) >= 8) {
                break;
            }

            if (is_scalar($value) && ! preg_match('/secret|pin|password|token|key$/i', (string) $key)) {
                $label = ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', (string) $key))));
                $out[] = ['label' => $label, 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : mb_substr((string) $value, 0, 120)];
            }
        }

        return $out;
    }
}
