<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Notifications\Support\AlertRecipients;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * The signed unsubscribe link of an alert email (module 7.8): turns one alert type off for that user in that
 * business, or (`digest`) every type they get in the daily digest. Returns the labels turned off, or null when the
 * user is no longer an active member (nothing to change).
 */
class UnsubscribeFromAlerts
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return list<string>|null
     */
    public function handle(string $companyId, int $userId, string $type): ?array
    {
        $recipient = AlertRecipients::find($companyId, $userId);
        $single = AlertType::tryFrom($type);

        if ($recipient === null || ($single === null && $type !== AlertLinks::DIGEST)) {
            return null;
        }

        $types = $single !== null ? [$single] : array_values(array_filter(AlertType::cases(), fn (AlertType $t) => $recipient->delivery($t) === AlertDelivery::Digest));
        $preference = AlertPreference::withoutCompanyScope()->firstOrNew(['company_id' => $companyId, 'user_id' => $userId]);
        $deliveries = array_map(fn (AlertDelivery $d) => $d->value, $recipient->deliveries);

        foreach ($types as $t) {
            $deliveries[$t->value] = AlertDelivery::Off->value;
        }

        $preference->fill(['deliveries' => $deliveries, 'branch_ids' => $recipient->branchIds])->save();
        $this->audit->handle('alerts.unsubscribed', $preference, null, ['types' => array_map(fn (AlertType $t) => $t->value, $types)], ['via' => 'email link'], companyId: $companyId);

        return array_map(fn (AlertType $t) => $t->label(), $types);
    }
}
