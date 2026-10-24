<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Mail\Sending\SmtpErrorText;
use App\Domain\OnlineStore\Mail\Sending\StoreSmtpMailer;
use App\Domain\OnlineStore\Mail\Sending\StoreSmtpUnavailable;
use App\Domain\OnlineStore\Mail\StoreMailIdentity;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The Emails tab's "Send a test email" (OS-10, rate limited by the route): a sample "We've got your order" email in
 * the store's look, to the signed-in user's own address only (never an address typed in).
 *
 * - Own server switched on: sent straight away through it. Success marks the server verified (customer emails use it
 *   from now on) and clears the last error; a failure stores the reason in plain words (no secrets), clears
 *   "verified" and is returned for the page.
 * - Otherwise: queued through the platform mailer, in the shop's name.
 *
 * @phpstan-type TestResult array{sent: bool, message: string}
 */
final class SendStoreTestEmail
{
    public function __construct(
        private readonly StoreSmtpMailer $mailers,
        private readonly OnlineOrderMessages $messages,
        private readonly RecordAudit $audit,
        private readonly CurrentCompany $tenancy,
    ) {}

    /**
     * @return TestResult
     */
    public function handle(Company $company, User $user): array
    {
        return $this->tenancy->runAs($company, function () use ($company, $user): array {
            $store = StoreScope::storeOf($company);
            $settings = StoreMailIdentity::settings($store);
            $replyTo = StoreMailIdentity::replyTo($settings, $this->messages->shopAddress($store, $company));
            $mail = OnlineOrderReceivedMail::sampleFor(
                StoreMailIdentity::brand($store, $company, $settings),
                StoreMailIdentity::senderName($company, $settings),
                $replyTo,
                $company->id,
            )->asTest();
            $mail->to($user->email, $user->name);

            $result = $settings !== null && $settings->smtp_enabled
                ? $this->throughOwnServer($settings, $mail, $user)
                : $this->throughPlatform($mail, $user);

            $this->audit->handle('online_store.test_email_sent', $store, meta: ['to' => $user->email, 'own_server' => $settings?->smtp_enabled === true, 'sent' => $result['sent']], companyId: $company->id);

            return $result;
        });
    }

    /**
     * @return TestResult
     */
    private function throughOwnServer(OnlineStoreMailSettings $settings, OnlineOrderReceivedMail $mail, User $user): array
    {
        $mail->smtpFrom = $settings->smtp_from_address;

        try {
            $mail->send($this->mailers->make($settings));
        } catch (TransportExceptionInterface|StoreSmtpUnavailable $error) {
            $reason = SmtpErrorText::for($error, $settings);
            $settings->forceFill(['smtp_verified_at' => null, 'last_error' => $reason, 'last_error_at' => now(), 'last_test_at' => now()])->save();
            Log::warning('Online store test email: the shop\'s own email server failed', ['company_id' => $settings->company_id, 'host' => $settings->smtp_host, 'reason' => $reason]);

            return ['sent' => false, 'message' => $reason];
        }

        $settings->forceFill(['smtp_verified_at' => now(), 'last_error' => null, 'last_error_at' => null, 'last_test_at' => now()])->save();

        return ['sent' => true, 'message' => "Test email sent to {$user->email} through {$settings->smtp_host}. Customer emails now go from your own email server."];
    }

    /**
     * @return TestResult
     */
    private function throughPlatform(OnlineOrderReceivedMail $mail, User $user): array
    {
        $mail->platformOnly = true;
        Mail::queue($mail);

        return ['sent' => true, 'message' => "Test email on its way to {$user->email}, in your shop's name."];
    }
}
