<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * "Send all held emails" of one business (P11), once the owner has checked its plan, fees, tills and amounts: every
 * waiting email, oldest first, through SendHeldEmail (each audited). One that cannot go (a void invoice, a login
 * that is gone) is left waiting and reported.
 */
class SendCompanyHeldEmails
{
    public function __construct(private readonly SendHeldEmail $send) {}

    /**
     * @return array{sent: int, failed: list<string>}
     */
    public function handle(Company $company): array
    {
        $sent = 0;
        $failed = [];

        foreach (HeldEmail::query()->waiting()->where('company_id', $company->id)->orderBy('created_at')->orderBy('id')->get() as $held) {
            try {
                $this->send->handle($held);
                $sent++;
            } catch (ValidationException $exception) {
                $failed[] = ($held->subject ?? $held->template).': '.collect($exception->errors())->flatten()->first();
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
