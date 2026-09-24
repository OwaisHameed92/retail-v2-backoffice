<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Actions\RenderEmailPreview;
use App\Domain\Mail\Actions\SendTestEmail;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Support\EmailTemplates;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email templates (module 1.7): list, live preview with sample data, and "send test to me".
 */
class EmailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $templates = EmailTemplates::all();
        $selected = (string) $request->string('template');

        if (EmailTemplates::find($selected) === null) {
            $selected = $templates[0]['key'];
        }

        return Inertia::render('admin/emails/templates', [
            'templates' => $templates,
            'selected' => $selected,
            'toast' => $request->session()->get('emailToast'),
        ]);
    }

    /**
     * The rendered sample, for the preview iframe. `?format=text` returns the plain-text part.
     */
    public function preview(Request $request, string $template, RenderEmailPreview $render): HttpResponse
    {
        $preview = $render->handle($this->sample($template));

        if ($request->query('format') === 'text') {
            return response($preview['text'], 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($preview['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            // Email HTML has no scripts: block them outright, allow inline styles and remote images only.
            'Content-Security-Policy' => "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'; form-action 'none'",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function sendTest(Request $request, string $template, SendTestEmail $sendTest): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        $mailable = $this->sample($template);
        $sendTest->handle($admin, $mailable);

        return redirect()
            ->route('admin.emails.templates', ['template' => $template])
            ->with('emailToast', [
                'id' => (string) Str::ulid(),
                'message' => "Test of \"{$mailable::templateLabel()}\" queued for {$admin->email}.",
            ]);
    }

    private function sample(string $template): BrandedMailable
    {
        return EmailTemplates::sample($template) ?? abort(404);
    }
}
