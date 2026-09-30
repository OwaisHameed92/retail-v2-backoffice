<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\BillingRequestKind;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Mail\Data\TillRequestData;
use App\Domain\Mail\Mailables\AdminSubscriptionRequestMail;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Cancel my subscription" / "Change my bank account" on My subscription (module 4.10). The business never cancels
 * its own licences and cannot move its Direct Debit: this tells Switch & Save. Like "Ask for more tills" (4.7) the
 * request is an admin licence alert (`subscriptionRequested`) on the business's oldest live licence (else its newest
 * licence), so it shows on the admin dashboard's "Needs attention" and the licence page until staff resolve it. One
 * open request per kind: asking again counts up the open one. Staff also get AdminSubscriptionRequestMail. Audited
 * as `billing.request_sent`. Nothing about the account, licences or Direct Debit changes here.
 */
final class SendBillingRequest
{
    public const MAX_MESSAGE = 1000;

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $requester, BillingRequestKind $kind, ?string $message = null, ?string $phone = null): ?LicenceAlert
    {
        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['kind' => 'Your subscription is already cancelled.']);
        }

        $message = $message === null || trim($message) === '' ? null : mb_substr(trim($message), 0, self::MAX_MESSAGE);
        $now = CarbonImmutable::now();
        $details = array_filter([
            'summary' => $kind->label().' requested · asked by '.$requester->name.($message !== null ? ' · “'.mb_substr($message, 0, 300).'”' : ''),
            'kind' => $kind->value,
            'message' => $message,
            'requestedBy' => $requester->name,
            'requestedByEmail' => $requester->email,
            'phone' => $phone,
        ], fn ($value) => $value !== null);

        $alert = $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $kind, $details, $now): ?LicenceAlert {
            $licence = Licence::query()->live()->orderBy('created_at')->orderBy('id')->first()
                ?? Licence::query()->orderByDesc('created_at')->orderByDesc('id')->first();
            $open = $licence === null ? null : LicenceAlert::query()->where('type', LicenceAlertType::SubscriptionRequested->value)
                ->where('fingerprint', self::fingerprint($kind))->open()->lockForUpdate()->first();

            if ($open !== null) {
                $open->forceFill(['count' => $open->count + 1, 'last_seen_at' => $now, 'details' => $details])->save();
            } elseif ($licence !== null) {
                $open = LicenceAlert::query()->create([
                    'company_id' => $company->id, 'licence_id' => $licence->id, 'type' => LicenceAlertType::SubscriptionRequested,
                    'fingerprint' => self::fingerprint($kind), 'details' => $details, 'first_seen_at' => $now, 'last_seen_at' => $now, 'count' => 1,
                ]);
            }

            $this->audit->handle('billing.request_sent', $open ?? $company, null, ['kind' => $kind->value], ['count' => $open->count ?? 1], companyId: $company->id);

            return $open;
        }));

        Mail::queue(new AdminSubscriptionRequestMail(new TillRequestData(
            businessName: $company->name,
            companyId: $company->id,
            kind: $kind->label(),
            what: $kind->what(),
            requestedBy: $requester->name,
            email: $requester->email,
            phone: $phone,
            receivedAt: $now,
            message: $message,
            count: $alert->count ?? 1,
        )));

        return $alert;
    }

    public static function fingerprint(BillingRequestKind $kind): string
    {
        return hash('sha256', LicenceAlertType::SubscriptionRequested->value.'|'.$kind->value);
    }
}
