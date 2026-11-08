<?php

namespace Tests\Feature\Salon;

use App\Domain\Salon\Models\SalonOnlineShop;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Support\ContractSchema;
use Tests\TestCase;

/**
 * W3a test kit: the Kirkgate business of SyncApiFixtures with Leeds a Salon / Barber shop (its till sent
 * `vertical.salon`) and Bradford a grocery, SALON_ONLINE on, and helpers for the five salon calls and the public pages.
 * The pack's samples (salon/samples/*.json) are the bodies; the page's day is 2026-11-09 (GMT).
 */
final class SalonOnline
{
    public const ANN = '01K9S0000000000000000ST001';

    public const BEN = '01K9S0000000000000000ST002';

    public const CUT = '01K9S0000000000000000SV001';

    public const SHORT = '01K9S0000000000000000SV003';

    public const LONG = '01K9S0000000000000000SV004';

    public const BEARD = '01K9S0000000000000000SV005';

    public SyncApiFixtures $sync;

    public function __construct(private readonly TestCase $test)
    {
        config(['salon_online.enabled' => true]);
        $this->sync = new SyncApiFixtures($test);
        $this->sync->push([SalonRows::setting('vertical.salon', 'true', 1)])->assertOk();
    }

    /**
     * A pack sample, decoded.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $name): array
    {
        return json_decode((string) file_get_contents(ContractSchema::salonDir("salon/samples/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The booking page sample with members replaced (dot paths, e.g. `rules.holdMin`).
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    public static function page(array $set = []): array
    {
        $page = self::sample('booking-page');

        foreach ($set as $path => $value) {
            data_set($page, $path, $value);
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string|null>  $headers
     */
    public function call(string $method, string $path, array|string|null $body = null, bool $gzip = false, array $headers = [], bool $bradford = false): TestResponse
    {
        $json = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $content = $json === null ? null : ($gzip ? (string) gzencode($json) : $json);
        $extra = $json === null ? [] : ['Content-Type' => 'application/json; charset=utf-8', ...($gzip ? ['Content-Encoding' => 'gzip'] : [])];

        return $this->sync->send($method, '/api/v1/sync/salon/'.$path, [...$extra, ...$headers], $content, $bradford);
    }

    /**
     * @param  array<string, mixed>|string|null  $page
     * @param  array<string, string|null>  $headers
     */
    public function putPage(array|string|null $page = null, array $headers = [], bool $bradford = false): TestResponse
    {
        return $this->call('PUT', 'booking-page', $page ?? self::page(), true, $headers, $bradford);
    }

    /**
     * @param  array<string, mixed>  $set
     */
    public function heartbeat(array $set = [], bool $bradford = false): TestResponse
    {
        return $this->call('PUT', 'heartbeat', [...self::sample('heartbeat'), ...$set], bradford: $bradford);
    }

    /** The page stored and the till heard now: the booking page is open. */
    public function open(array $page = []): SalonOnlineShop
    {
        $this->putPage(self::page($page))->assertOk();
        $this->heartbeat(['at' => now('UTC')->toIso8601ZuluString()])->assertOk();

        return $this->shop();
    }

    public function shop(bool $bradford = false): SalonOnlineShop
    {
        return SalonOnlineShop::withoutCompanyScope()->where('branch_id', $bradford ? $this->sync->bradford->id : $this->sync->leeds->id)->firstOrFail();
    }

    /** The public address of a page of Leeds (`''` = the booking page). */
    public function url(string $path = ''): string
    {
        return '/book/'.$this->shop()->slug.($path === '' ? '' : '/'.$path);
    }

    /**
     * The till pushes rows (SalonRows envelopes) for Leeds.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function push(array $changes): TestResponse
    {
        return $this->sync->push($changes);
    }

    /**
     * An appointment of Leeds with one service, as its till pushes it.
     *
     * @return list<array<string, mixed>>
     */
    public static function appointment(string $id, string $startsAt, ?string $customerId, int $seq, string $productId = self::CUT, string $stylist = self::BEN, string $status = 'booked'): array
    {
        $ends = date('Y-m-d\TH:i:s\Z', (int) strtotime($startsAt) + 1800);

        return [
            SalonRows::envelope('SalonAppointment', $id, $seq, [
                'customerId' => $customerId, 'clientName' => 'Zoe Patel', 'clientPhone' => '07700900123', 'clientEmail' => 'zoe@example.co.uk',
                'startsAt' => $startsAt, 'endsAt' => $ends, 'status' => $status, 'onlineBookingId' => null, 'repeatRuleId' => null,
                'notes' => 'Private note', 'cancelledAt' => null, 'arrivedAt' => null, 'completedAt' => null, 'cancelledBy' => null,
                'saleId' => null, 'feeSaleId' => null, 'depositSaleId' => null, 'bookedByUserId' => null,
            ]),
            SalonRows::envelope('SalonAppointmentService', substr($id, 0, 22).'SV01', $seq + 1, [
                'appointmentId' => $id, 'productId' => $productId, 'stylistUserId' => $stylist, 'assistantUserId' => null,
                'levelId' => null, 'resourceId' => null, 'startsAt' => $startsAt, 'endsAt' => $ends, 'sortOrder' => 0,
            ]),
        ];
    }

    /** The JSON a public write sends (a fresh form key unless given). */
    public function postJson(string $url, array $data): TestResponse
    {
        return $this->test->postJson($url, ['clientKey' => bin2hex(random_bytes(8)), ...$data]);
    }
}
