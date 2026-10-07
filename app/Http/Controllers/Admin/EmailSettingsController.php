<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Data\HeldEmailData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmailSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Emails (P11, billing.manage): "Send automatically" per tenant email category, and every held
 * email across businesses with Send / Discard (the business page has its own list).
 */
class EmailSettingsController extends Controller
{
    private const LIST_LIMIT = 100;

    public function show(Request $request): Response
    {
        $settings = EmailControl::all();
        $waiting = HeldEmail::query()->waiting()->toBase()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');
        $held = HeldEmail::query()->waiting()->with('company')->latest('created_at')->latest('id')->limit(self::LIST_LIMIT)->get();
        $recent = HeldEmail::query()->where('status', '!=', HeldEmail::HELD)->with(['company', 'actionedBy'])->latest('actioned_at')->limit(10)->get();

        return Inertia::render('admin/settings/emails', [
            'categories' => array_map(fn (EmailCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
                'sendAutomatically' => $settings[$category->value],
                'held' => (int) ($waiting[$category->value] ?? 0),
            ], EmailCategory::cases()),
            'held' => $held->map(fn (HeldEmail $email) => HeldEmailData::row($email))->values(),
            'heldTotal' => HeldEmail::query()->waiting()->count(),
            'recent' => $recent->map(fn (HeldEmail $email) => HeldEmailData::row($email))->values(),
        ]);
    }

    public function update(EmailSettingsRequest $request, UpdateEmailSettings $update): RedirectResponse
    {
        $settings = $update->handle($request->settings(), (string) $request->user('admin')?->getKey() ?: null);
        $off = count(array_filter($settings, fn (bool $on) => ! $on));

        return back()->with('success', $off === 0
            ? 'Email settings saved. Every email goes automatically.'
            : 'Email settings saved. '.($off === 1 ? '1 kind of email is' : "{$off} kinds of email are").' held for you to send.');
    }
}
