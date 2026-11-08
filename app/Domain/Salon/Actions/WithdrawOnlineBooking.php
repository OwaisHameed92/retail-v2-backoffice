<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Exceptions\SalonBookingRefused;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The client withdraws a request the till has not answered yet (W3a, salon pack README §2 `pending → cancelled`,
 * checklist 5): the row goes to the shop's till as `cancelled` in its next pull. Once the till has answered, the row is
 * the till's (SalonOnlineBookingRules refuses the change): the client is told to use the manage link or ring the shop.
 */
final class WithdrawOnlineBooking
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(SalonOnlineShop $shop, string $requestId): SalonOnlineBooking
    {
        return $this->tenancy->runAs($shop->company()->firstOrFail(), fn (): SalonOnlineBooking => DB::transaction(function () use ($shop, $requestId): SalonOnlineBooking {
            $booking = SalonOnlineBooking::query()->where('branch_id', $shop->branch_id)->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($booking->status === SalonOnlineBookingStatus::Cancelled) {
                return $booking;
            }

            try {
                $booking->forceFill(['status' => SalonOnlineBookingStatus::Cancelled, 'row_version' => $booking->row_version + 1])->save();
            } catch (SalonBookingRefused) {
                throw ValidationException::withMessages(['request' => 'The shop has already answered this request. To change it, use the link in your confirmation or ring the shop.']);
            }

            return $booking;
        }));
    }
}
