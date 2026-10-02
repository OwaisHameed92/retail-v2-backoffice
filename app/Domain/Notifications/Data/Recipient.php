<?php

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Tenancy\Enums\CompanyRole;

/**
 * A member of a business who may get alerts (module 7.8), with their choices already resolved against the role:
 * a type the role cannot see is always off.
 */
final readonly class Recipient
{
    /**
     * @param  array<string, AlertDelivery>  $deliveries  by AlertType value
     * @param  list<string>|null  $branchIds  shops picked by a multi-shop user (null = every shop)
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public CompanyRole $role,
        public ?string $restrictedBranchId,
        public array $deliveries,
        public ?array $branchIds,
    ) {}

    public function delivery(AlertType $type): AlertDelivery
    {
        return $this->deliveries[$type->value] ?? AlertDelivery::Off;
    }

    public function wants(AlertType $type): bool
    {
        return $this->delivery($type) !== AlertDelivery::Off;
    }

    /** Whether this type goes in the user's daily digest ({@see AlertType::digestedWith()}). */
    public function inDigest(AlertType $type): bool
    {
        return $type->digestedWith($this->delivery($type));
    }

    /** Whether the user gets a daily digest at all. */
    public function getsDigest(): bool
    {
        foreach (AlertType::cases() as $type) {
            if ($this->inDigest($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether something about this shop (null = the whole business) is for this user: a one-shop user only hears
     * about their shop, a multi-shop user about the shops they picked plus business-wide things.
     */
    public function covers(?string $branchId): bool
    {
        if ($this->restrictedBranchId !== null) {
            return $branchId === $this->restrictedBranchId;
        }

        return $branchId === null || $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }
}
