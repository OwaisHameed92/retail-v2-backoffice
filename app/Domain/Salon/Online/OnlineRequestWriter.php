<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Models\SalonOnlineRequestSource;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes an online request as a hub-owned `SalonOnlineBooking` row for one shop (W3a, salon pack README §5), through
 * the model, so W2's SalonOnlineBookingRules check the portal's side of the field ownership and the row goes in that
 * shop's pull only. Every request is `pending`, names the shop, carries a reference the client sees (`WEB-` + six
 * random characters, never a sequence) and none of the till's answer members.
 *
 * The form's own key (`clientKey`) makes a double tap or a resend one request: the same key again returns the first.
 * `src` (where the client came from) is kept apart, never sent to the till. Runs in the shop's company.
 */
final class OnlineRequestWriter
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param  array<string, mixed>  $attributes  the request's portal members (snake_case columns)
     * @param  (callable(): array<string, mixed>)|null  $before  runs first in the same transaction (e.g. the Customer
     *                                                           row, which must reach the till first); its result is merged into the attributes
     */
    public static function write(SalonOnlineShop $shop, array $attributes, string $clientKey, string $src, ?callable $before = null): SalonOnlineBooking
    {
        $existing = self::find($shop, $clientKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($shop, $attributes, $clientKey, $src, $before): SalonOnlineBooking {
                $now = CarbonImmutable::now('UTC');
                $booking = new SalonOnlineBooking;
                $booking->forceFill([
                    'branch_id' => $shop->branch_id,
                    'reference' => self::reference(),
                    'status' => SalonOnlineBookingStatus::Pending,
                    'appointment_id' => null,
                    'customer_id' => null,
                    'notes' => '',
                    'answers_json' => '[]',
                    'services_json' => '[]',
                    'terms_accepted_at' => null,
                    'marketing_sms' => false,
                    'marketing_email' => false,
                    'marketing_whats_app' => false,
                    'deposit_paid' => '0.00',
                    'payment_reference' => '',
                    'expires_at' => null,
                    'decided_at' => null,
                    'decided_by_user_id' => null,
                    'decision_reason' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                    ...$attributes,
                    ...($before === null ? [] : $before()),
                ])->save();

                SalonOnlineRequestSource::query()->create([
                    'id' => $booking->id, 'branch_id' => $shop->branch_id, 'client_key' => $clientKey,
                    'src' => mb_substr($src, 0, 30), 'created_at' => $now,
                ]);

                return $booking;
            });
        } catch (QueryException $e) {
            // The same form sent twice at once: the other one won.
            return self::find($shop, $clientKey) ?? throw $e;
        }
    }

    /** The request this form key already made, if any. */
    public static function find(SalonOnlineShop $shop, string $clientKey): ?SalonOnlineBooking
    {
        $id = SalonOnlineRequestSource::query()->where('branch_id', $shop->branch_id)->where('client_key', $clientKey)->value('id');

        return $id === null ? null : SalonOnlineBooking::query()->where('branch_id', $shop->branch_id)->find($id);
    }

    private static function reference(): string
    {
        do {
            $code = 'WEB-';

            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (SalonOnlineBooking::query()->where('reference', $code)->exists());

        return $code;
    }
}
