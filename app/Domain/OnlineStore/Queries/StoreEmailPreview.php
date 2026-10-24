<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Mail\OnlineOrderMailable;
use App\Domain\OnlineStore\Mail\StoreMailIdentity;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Models\Company;

/**
 * One customer email for the Emails tab's preview (OS-10): the template's sample order in the business's own look,
 * sender name and Reply-To, as saved now. Null when the business has no store or the key is not a customer email.
 */
final class StoreEmailPreview
{
    public static function for(Company $company, string $key): ?OnlineOrderMailable
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $class = null;

        foreach (StoreEmailsPage::customerMails() as $candidate) {
            if ($candidate::templateKey() === $key) {
                $class = $candidate;
            }
        }

        if ($store === null || $class === null) {
            return null;
        }

        $settings = StoreMailIdentity::settings($store);

        return $class::sampleFor(
            StoreMailIdentity::brand($store, $company, $settings),
            StoreMailIdentity::senderName($company, $settings),
            StoreMailIdentity::replyTo($settings, app(OnlineOrderMessages::class)->shopAddress($store, $company)),
            $company->id,
        );
    }
}
