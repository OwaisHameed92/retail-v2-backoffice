<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Mail\Actions\DiscardHeldEmail;
use App\Domain\Mail\Actions\RenderEmailPreview;
use App\Domain\Mail\Actions\SendCompanyHeldEmails;
use App\Domain\Mail\Actions\SendHeldEmail;
use App\Domain\Mail\Actions\SendTenantStartEmail;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\HeldEmailMessage;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;

/**
 * Held emails (P11, billing.manage): send, discard or preview one; "Send all held emails" of a business; the business
 * page's "Send welcome email" and "Send set-password link". Every send and discard is audited by its action.
 */
class HeldEmailController extends Controller
{
    public function send(string $held, SendHeldEmail $send): RedirectResponse
    {
        $email = $send->handle($this->find($held));

        return back()->with('success', 'Email sent to '.$email->to.'.');
    }

    public function discard(string $held, DiscardHeldEmail $discard): RedirectResponse
    {
        $email = $discard->handle($this->find($held));

        return back()->with('success', 'Email to '.$email->to.' discarded. Nothing was sent.');
    }

    /** The email exactly as Send would send it now, in the sandboxed preview frame (no password link is made). */
    public function preview(string $held, HeldEmailMessage $message, RenderEmailPreview $render): HttpResponse
    {
        $email = $this->find($held);

        try {
            $html = $render->handle($message->forPreview($email))['html'];
        } catch (ValidationException $exception) {
            $html = '<p style="font-family:sans-serif">'.e((string) collect($exception->errors())->flatten()->first()).'</p>';
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'; form-action 'none'",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function sendAll(Company $company, SendCompanyHeldEmails $send): RedirectResponse
    {
        $result = $send->handle($company);

        if ($result['sent'] === 0 && $result['failed'] === []) {
            return back()->with('success', "No emails are waiting for {$company->name}.");
        }

        if ($result['failed'] !== []) {
            throw ValidationException::withMessages(['email' => ($result['sent'] > 0 ? "{$result['sent']} sent. " : '').'Not sent: '.implode('; ', $result['failed'])]);
        }

        return back()->with('success', ($result['sent'] === 1 ? '1 email' : "{$result['sent']} emails")." sent for {$company->name}.");
    }

    public function welcome(Company $company, SendTenantStartEmail $send): RedirectResponse
    {
        $count = $send->welcome($company);

        return back()->with('success', 'Welcome email with the licence keys sent'.($count > 1 ? " ({$count})" : '').'.');
    }

    public function passwordLink(Company $company, SendTenantStartEmail $send): RedirectResponse
    {
        $count = $send->passwordLink($company);

        return back()->with('success', 'Set-password link sent to '.($count === 1 ? '1 person' : "{$count} people").'.');
    }

    private function find(string $id): HeldEmail
    {
        return HeldEmail::query()->findOrFail($id);
    }
}
