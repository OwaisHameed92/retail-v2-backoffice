<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Availability\StylistChoice;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\BookingTimes;
use App\Domain\Salon\Online\OnlineCustomer;
use App\Domain\Salon\Online\OnlineRequestWriter;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\SalonOnlineBookingKind;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A client's booking request from the public booking page (W3a, salon pack README §5, §6, §10, §11): writes a
 * `SalonOnlineBooking` row (kind `new`, status `pending`, `expiresAt` = now + `holdMin`) for the shop, which goes in its
 * pull. The portal never books: the till accepts or declines, and the client is told only then.
 *
 * Refused (ValidationException, in the client's words) when the page is closed, a service or the stylist is not on the
 * page, or the time is no longer offered (the till's published ranges, minimum notice, the clock-change hour, times
 * held for earlier requests). While the till is away ("request only") requests are still taken.
 *
 * The client is linked to a `Customer` (OnlineCustomer, saved first so it reaches the till first), so the marketing
 * ticks (explicit, never pre-ticked, apart from the terms) can become Consent rows when the till accepts.
 */
final class RequestOnlineBooking
{
    public const PATCH_TEST_QUESTION = 'Have you had a patch test with us for this service?';

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array{options: list<string>, addOns: list<string>, stylist: string, date: string, time: string, firstName: string, lastName: string, phone: string, email: string, notes: string, answers: list<string>, patchTest: string|null, terms: bool, marketingSms: bool, marketingEmail: bool, marketingWhatsApp: bool, src: string, clientKey: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(SalonOnlineShop $shop, array $data): SalonOnlineBooking
    {
        $now = CarbonImmutable::now('UTC');
        $company = $shop->company()->firstOrFail();
        $sent = $this->tenancy->runAs($company, fn () => OnlineRequestWriter::find($shop, $data['clientKey']));

        if ($sent !== null) {
            return $sent;   // the same form again (a double tap or a resend): one request
        }

        if (SalonShopStatus::booking($shop, $now) === SalonShopStatus::CLOSED) {
            throw ValidationException::withMessages(['time' => 'The shop is not taking online bookings just now. Please ring the shop.']);
        }

        $menu = new PageMenu((array) $shop->page);
        $basket = $menu->basket($data['options'], $data['addOns']) ?? throw ValidationException::withMessages(['options' => 'Choose a service from the list.']);
        $rules = $menu->rules();

        if ($rules['terms'] !== '' && ! $data['terms']) {
            throw ValidationException::withMessages(['terms' => 'Accept the shop\'s terms to send your request.']);
        }

        $patch = $rules['askPatchTest'] && in_array(true, array_column($basket, 'needsPatchTest'), true);

        if ($patch && ! in_array($data['patchTest'], ['yes', 'no'], true)) {
            throw ValidationException::withMessages(['patchTest' => 'Tell the shop whether you have had a patch test.']);
        }

        $start = BookingTimes::check($shop, $basket, $data['stylist'], $data['date'], $data['time'], $now)
            ?? throw ValidationException::withMessages(['time' => 'That time has just gone. Choose another time.']);

        $answers = [];

        foreach ($rules['questions'] as $i => $question) {
            $answers[] = ['question' => $question, 'answer' => trim((string) ($data['answers'][$i] ?? ''))];
        }

        if ($patch) {
            $answers[] = ['question' => self::PATCH_TEST_QUESTION, 'answer' => $data['patchTest'] === 'yes' ? 'Yes' : 'No'];
        }

        $notes = trim(StylistChoice::wish($data['stylist']).' '.trim($data['notes']));

        return $this->tenancy->runAs($company, fn (): SalonOnlineBooking => OnlineRequestWriter::write($shop, [
            'kind' => SalonOnlineBookingKind::New,
            'requested_starts_at' => $start,
            'requested_user_id' => StylistChoice::requestedUser($data['stylist']),
            'services_json' => (string) json_encode(array_map(fn (array $item) => ['productId' => $item['productId']], $basket), JSON_UNESCAPED_SLASHES),
            'client_first_name' => trim($data['firstName']),
            'client_last_name' => trim($data['lastName']),
            'client_phone' => trim($data['phone']),
            'client_email' => trim($data['email']),
            'notes' => mb_substr($notes, 0, 500),
            'answers_json' => (string) json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'terms_accepted_at' => $rules['terms'] !== '' ? $now : null,
            'marketing_sms' => $data['marketingSms'],
            'marketing_email' => $data['marketingEmail'],
            'marketing_whats_app' => $data['marketingWhatsApp'],
            'expires_at' => $now->addMinutes(max(1, (int) $rules['holdMin'])),
        ], $data['clientKey'], $data['src'], fn (): array => [
            'customer_id' => OnlineCustomer::matchOrCreate($data['firstName'], $data['lastName'], $data['phone'], $data['email']),
        ]));
    }
}
