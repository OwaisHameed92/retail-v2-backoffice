<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\OnlineStore\Mail\OnlineOrderMailable;
use App\Domain\OnlineStore\Mail\StoreMailIdentity;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Models\Company;

/**
 * The online store's Emails tab (OS-10, `app/online-store/emails`): who customer emails come from, the store's own
 * SMTP server (never the password: only whether one is saved and its last 4 characters), how emails are sent now
 * (platform, own server waiting for a test, own server), the last problem, and the customer emails to preview.
 * No store yet → `store` is null and the page says the online store is not switched on.
 */
final class StoreEmailsPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, bool $canEdit, string $testRecipient): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $settings = $store === null ? null : StoreMailIdentity::settings($store);

        return [
            'store' => $store === null ? null : self::store($store, $company, $settings),
            'templates' => array_map(fn (string $class) => [
                'key' => $class::templateKey(),
                'label' => $class::templateLabel(),
                'description' => $class::templateDescription(),
                'subject' => $class::sample()->subjectLine(),
            ], self::customerMails()),
            'ports' => OnlineStoreMailSettings::PORTS,
            'testRecipient' => $testRecipient,
            'canEdit' => $canEdit,
        ];
    }

    /**
     * The online store's emails to a shop's customers, as the admin template list has them (any added later too).
     *
     * @return list<class-string<OnlineOrderMailable>>
     */
    public static function customerMails(): array
    {
        return array_values(array_filter(EmailTemplates::mailables(), fn (string $class) => is_subclass_of($class, OnlineOrderMailable::class) && $class::sendsInShopName()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function store(OnlineStore $store, Company $company, ?OnlineStoreMailSettings $settings): array
    {
        $orderAddress = app(OnlineOrderMessages::class)->shopAddress($store, $company);
        $mode = match (true) {
            $settings?->usesOwnServer() === true => 'ownServer',
            $settings?->smtpComplete() === true => 'needsTest',
            default => 'platform',
        };

        return [
            'businessName' => $company->name,
            'orderAddress' => $orderAddress,
            // OSA-14: the Reply-To fallback is the order email, else the business email; never a sign-in email.
            'orderAddressSource' => $orderAddress === null ? null : (filled($store->order_email) ? 'orderEmail' : 'businessEmail'),
            'platformFrom' => (string) config('mail.from.address'),
            'senderName' => $settings?->sender_name,
            'replyTo' => $settings?->reply_to,
            'smtp' => [
                'enabled' => (bool) $settings?->smtp_enabled,
                'host' => $settings?->smtp_host,
                'port' => $settings?->smtp_port,
                'encryption' => $settings?->smtp_encryption,
                'username' => $settings?->smtp_username,
                'fromAddress' => $settings?->smtp_from_address,
                'passwordSaved' => filled($settings?->smtp_password_last4),
                'passwordLast4' => $settings?->smtp_password_last4,
                'saved' => filled($settings?->smtp_host),
            ],
            'status' => [
                'mode' => $mode,
                'verifiedAt' => $settings?->smtp_verified_at?->toIso8601ZuluString(),
                'lastError' => $settings?->last_error,
                'lastErrorAt' => $settings?->last_error_at?->toIso8601ZuluString(),
                'lastTestAt' => $settings?->last_test_at?->toIso8601ZuluString(),
            ],
        ];
    }
}
