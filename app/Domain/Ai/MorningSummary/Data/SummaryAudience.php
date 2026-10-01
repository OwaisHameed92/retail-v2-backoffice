<?php

namespace App\Domain\Ai\MorningSummary\Data;

use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;

/**
 * Who a morning summary is cut for (module 6.3): the shops they cover (a one-shop user: theirs; a multi-shop user:
 * the shops picked in Settings → Notifications, or every shop), what their role may see, and the alert types already
 * in their 07:00 digest (those are not repeated inside the summary).
 */
final readonly class SummaryAudience
{
    /**
     * @param  list<string>|null  $branchIds  null = every shop
     * @param  list<string>  $digestTypes  AlertType values the user gets as digest sections
     */
    public function __construct(
        public ?array $branchIds,
        public CompanyRole $role,
        public array $digestTypes = [],
    ) {}

    public static function forRecipient(Recipient $recipient): self
    {
        $digest = array_values(array_map(
            fn (AlertType $type) => $type->value,
            array_filter(AlertType::cases(), fn (AlertType $type) => $type !== AlertType::MorningSummary && $recipient->delivery($type) === AlertDelivery::Digest),
        ));

        return new self(
            $recipient->restrictedBranchId !== null ? [$recipient->restrictedBranchId] : $recipient->branchIds,
            $recipient->role,
            $digest,
        );
    }

    public function covers(string $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    public function can(Ability $ability): bool
    {
        return $this->role->can($ability);
    }

    public function inDigest(AlertType $type): bool
    {
        return in_array($type->value, $this->digestTypes, true);
    }

    /** 'all', one shop's id, or a hash of the shops picked: matches the dashboard's shop switcher. */
    public function scopeKey(): string
    {
        if ($this->branchIds === null) {
            return 'all';
        }

        $ids = $this->branchIds;
        sort($ids);

        return count($ids) === 1 ? $ids[0] : sha1(implode(',', $ids));
    }
}
