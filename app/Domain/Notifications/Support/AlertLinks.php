<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Enums\AlertType;
use Illuminate\Support\Facades\URL;

/**
 * Links in alert emails (module 7.8): portal screens and the signed unsubscribe link (per user, business and alert
 * type, or `digest` for the whole daily digest). Signed, never expiring: an old email still unsubscribes.
 */
final class AlertLinks
{
    public const DIGEST = 'digest';

    public static function unsubscribe(string $companyId, int $userId, AlertType|string $type): string
    {
        return URL::signedRoute('app.alerts.unsubscribe', [
            'company' => $companyId,
            'user' => $userId,
            'type' => $type instanceof AlertType ? $type->value : $type,
        ]);
    }

    public static function settings(): string
    {
        return self::portal('/app/settings/notifications');
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function portal(string $path, array $query = []): string
    {
        return rtrim((string) config('sspos.portal_url'), '/').$path.($query === [] ? '' : '?'.http_build_query($query));
    }

    public static function shop(?string $branchId): string
    {
        return $branchId === null ? self::portal('/app/shops') : self::portal('/app/shops/'.$branchId);
    }

    public static function forType(AlertType $type, ?string $branchId = null): string
    {
        $shop = $branchId === null ? [] : ['shop' => $branchId];

        return match ($type) {
            AlertType::TillOffline, AlertType::SyncFailing => self::shop($branchId),
            AlertType::LowStock => self::portal('/app/stock', [...$shop, 'status' => 'low']),
            AlertType::CashVariance => self::portal('/app/cash/alerts', $shop),
            AlertType::Compliance => self::portal('/app/compliance', $shop),
            AlertType::SyncConflicts => self::portal('/app/sync/conflicts'),
            AlertType::MorningSummary => self::portal('/app'),
        };
    }
}
