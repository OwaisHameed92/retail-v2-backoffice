<?php

namespace App\Domain\Leads\Queries;

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use Illuminate\Support\Facades\Cache;

/**
 * The Leads sidebar pill (module 1.9): leads nobody has contacted yet (status "new", archived left out).
 * Cached for 60 seconds; shared on every admin page, so it must stay one cheap count.
 */
final class LeadNavCount
{
    public const CACHE_KEY = 'admin:nav-counts:leads';

    public const CACHE_SECONDS = 60;

    public static function get(): int
    {
        return (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => Lead::query()->where('status', LeadStatus::New->value)->count());
    }
}
