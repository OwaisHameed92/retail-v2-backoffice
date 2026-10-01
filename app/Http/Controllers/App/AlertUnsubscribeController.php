<?php

namespace App\Http\Controllers\App;

use App\Domain\Notifications\Actions\UnsubscribeFromAlerts;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unsubscribe link of an alert email (module 7.8). Public, signed (`signed` middleware): GET asks to confirm
 * (so a mail scanner opening the link changes nothing), POST to the same signed URL turns the alert off.
 */
class AlertUnsubscribeController extends Controller
{
    public function show(Request $request, string $company, int $user, string $type): Response
    {
        return Inertia::render('alerts/unsubscribe', [
            'state' => 'confirm',
            'business' => Company::query()->whereKey($company)->value('name') ?? 'your business',
            'what' => $this->what($type),
            'action' => $request->fullUrl(),
            'settingsUrl' => AlertLinks::settings(),
        ]);
    }

    public function store(Request $request, UnsubscribeFromAlerts $unsubscribe, string $company, int $user, string $type): Response
    {
        $done = $unsubscribe->handle($company, $user, $type);

        return Inertia::render('alerts/unsubscribe', [
            'state' => $done === null ? 'unavailable' : 'done',
            'business' => Company::query()->whereKey($company)->value('name') ?? 'your business',
            'what' => $this->what($type),
            'action' => null,
            'settingsUrl' => AlertLinks::settings(),
        ]);
    }

    private function what(string $type): string
    {
        return AlertType::tryFrom($type)?->label() ?? 'The daily summary';
    }
}
