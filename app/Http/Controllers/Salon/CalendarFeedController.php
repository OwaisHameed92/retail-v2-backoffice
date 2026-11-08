<?php

namespace App\Http\Controllers\Salon;

use App\Domain\Salon\Actions\BuildCalendarFeed;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use SensitiveParameter;

/**
 * `GET /salon/ical/{branchId}/{userId}/{feedToken}.ics` (salon README §12, W3b): a stylist's staff calendar feed for
 * their phone's calendar app. Public (the random token is the key), no session, rate limited (`salon-ical`). Any
 * refusal is the same plain 404. `Cache-Control: max-age=300`, private (it names clients by first name).
 */
class CalendarFeedController extends Controller
{
    public function __invoke(string $branchId, string $userId, #[SensitiveParameter] string $feedToken, BuildCalendarFeed $build): Response
    {
        $body = $build->handle($branchId, $userId, $feedToken, CarbonImmutable::now());

        if ($body === null) {
            return response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="salon-diary.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
