<?php

namespace App\Http\Controllers\App;

use App\Domain\Mail\Actions\RenderEmailPreview;
use App\Domain\OnlineStore\Actions\SaveStoreMailSettings;
use App\Domain\OnlineStore\Actions\SendStoreTestEmail;
use App\Domain\OnlineStore\Queries\StoreEmailPreview;
use App\Domain\OnlineStore\Queries\StoreEmailsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreMailSettingsRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The online store's Emails tab (OS-10, `store.manage`, 404 while the online store is off): sender name, Reply-To and
 * the store's own email server, a rate-limited test email to the signed-in user, and previews of the customer emails
 * in the store's look. A one-shop user may look only.
 */
class OnlineStoreEmailController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('app/online-store/emails', StoreEmailsPage::for($this->tenancy->require(), $this->tenancy->restrictedBranchId() === null, $user->email));
    }

    public function update(SaveStoreMailSettingsRequest $request, SaveStoreMailSettings $save): RedirectResponse
    {
        $settings = $save->handle($this->tenancy->require(), $request->settings());

        return to_route('app.online-store.emails')->with('success', match (true) {
            $request->boolean('smtp_forget') => 'Email server removed. Customer emails go through Switch & Save\'s email service in your shop\'s name.',
            $settings->smtp_enabled && $settings->smtp_verified_at === null => 'Saved. Send a test email to start using your own email server.',
            default => 'Email settings saved.',
        });
    }

    public function test(CompanyWideWriteRequest $request, SendStoreTestEmail $send): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $send->handle($this->tenancy->require(), $user);

        return to_route('app.online-store.emails')->with($result['sent'] ? 'success' : 'error', $result['sent'] ? $result['message'] : 'The test email did not go: '.$result['message']);
    }

    public function preview(Request $request, string $template, RenderEmailPreview $render): HttpResponse
    {
        $mail = StoreEmailPreview::for($this->tenancy->require(), $template);
        abort_if($mail === null, 404);
        $preview = $render->handle($mail);

        if ($request->query('format') === 'text') {
            return response($preview['text'], 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
        }

        return response($preview['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            // Email HTML has no scripts: block them, allow inline styles and remote images (the store's logo) only.
            'Content-Security-Policy' => "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'; form-action 'none'",
            'Cache-Control' => 'no-store',
        ]);
    }
}
