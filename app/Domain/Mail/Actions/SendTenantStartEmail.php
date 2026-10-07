<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Tenancy\Actions\ResendPasswordSetupLink;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The business page's "Send welcome email" and "Send set-password link" (P11), for when the owner has checked the
 * business and wants its first emails to go:
 *
 * - Welcome: sends the held welcome email (its licence keys exist only there). None held: refused, with how to send
 *   keys instead ("Resend key" on a licence makes and emails a new key).
 * - Set-password link: sends the held links (each with a new 7-day link); none held: a new link to every active
 *   owner (ResendPasswordSetupLink, audited per user).
 *
 * Every send is audited. Returns how many emails went.
 */
class SendTenantStartEmail
{
    public function __construct(
        private readonly SendHeldEmail $sendHeld,
        private readonly ResendPasswordSetupLink $sendLink,
    ) {}

    /** @throws ValidationException */
    public function welcome(Company $company): int
    {
        $held = $this->held($company, WelcomeTenantMail::templateKey());

        if ($held === []) {
            throw ValidationException::withMessages(['email' => "No welcome email is waiting for {$company->name}. It was already sent or discarded; licence keys can be sent again with “Resend key” on each licence."]);
        }

        foreach ($held as $email) {
            $this->sendHeld->handle($email);
        }

        return count($held);
    }

    /** @throws ValidationException */
    public function passwordLink(Company $company): int
    {
        $held = $this->held($company, SetPasswordMail::templateKey());

        if ($held !== []) {
            foreach ($held as $email) {
                $this->sendHeld->handle($email);
            }

            return count($held);
        }

        /** @var list<User> $owners */
        $owners = $company->owners()->orderBy('users.id')->get()->all();

        if ($owners === []) {
            throw ValidationException::withMessages(['email' => "{$company->name} has no active owner to email. Add an owner first."]);
        }

        EmailControl::manually(function () use ($owners, $company) {
            foreach ($owners as $owner) {
                $this->sendLink->handle($owner, $company);
            }
        });

        return count($owners);
    }

    /**
     * @return list<HeldEmail>
     */
    private function held(Company $company, string $template): array
    {
        return HeldEmail::query()->waiting()->where('company_id', $company->id)->where('template', $template)->orderBy('created_at')->get()->all();
    }
}
