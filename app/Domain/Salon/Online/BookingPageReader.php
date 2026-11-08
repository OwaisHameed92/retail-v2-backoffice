<?php

namespace App\Domain\Salon\Online;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * Checks a `PUT sync/salon/booking-page` body against `salon/schemas/booking-page.schema.json` (W3a, README §4.1, §10)
 * and returns it in one shape: integers as integers, money as "12.50", times as `…Z`. A member that breaks the schema
 * is `422 request.invalid` with `rejectedKey` = its path. The manage-link key is returned apart from the page, so the
 * stored page never holds it.
 */
final class BookingPageReader
{
    private const RANGE = '/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/';

    /**
     * @return array{page: array<string, mixed>, manageLink: array{keyId: string, key: string}|null}
     *
     * @throws ApiException
     */
    public static function read(mixed $body): array
    {
        $p = SalonPayload::of($body);
        $link = $p->nullableObject('manageLink');

        return [
            'page' => [
                'schemaVersion' => $p->int('schemaVersion', 0, 1000),
                'generatedAt' => $p->dateTime('generatedAt')->toIso8601ZuluString(),
                'timeZone' => $p->string('timeZone', 64),
                'shopName' => $p->string('shopName', 200),
                'rules' => self::rules($p->object('rules')),
                'services' => array_map(self::service(...), $p->objects('services', 500)),
                'stylists' => array_map(fn (SalonPayload $s) => [
                    'userId' => $s->string('userId', 64, false),
                    'firstName' => $s->string('firstName', 100),
                    'levelName' => $s->string('levelName', 100),
                    'group' => $s->string('group', 20),
                ], $p->objects('stylists', 200)),
                'availability' => self::availability($p->object('availability')),
            ],
            'manageLink' => $link === null ? null : ['keyId' => $link->string('keyId', 100, false), 'key' => $link->string('key', 200, false)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(SalonPayload $r): array
    {
        return [
            'isOpen' => $r->bool('isOpen'),
            'slotIntervalMin' => $r->int('slotIntervalMin', 1, 1440),
            'minNoticeMin' => $r->int('minNoticeMin', 0, 525600),
            'daysAhead' => $r->int('daysAhead', 0, 400),
            'bufferMin' => $r->int('bufferMin', 0, 1440),
            'changeWindowHours' => $r->int('changeWindowHours', 0, 8760),
            'holdMin' => $r->int('holdMin', 0, 10080),
            'offlineAfterMin' => $r->int('offlineAfterMin', 1, 1440),
            'autoAccept' => $r->bool('autoAccept'),
            'askPatchTest' => $r->bool('askPatchTest'),
            'questions' => $r->strings('questions', 50, 500),
            'terms' => $r->string('terms', 10000),
            'depositNote' => $r->string('depositNote', 2000),
            'queueJoinOpen' => $r->bool('queueJoinOpen'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function service(SalonPayload $s): array
    {
        return [
            'productId' => $s->string('productId', 64, false),
            'name' => $s->string('name', 200),
            'description' => $s->string('description', 2000),
            'categoryName' => $s->string('categoryName', 200),
            'clientGroup' => $s->string('clientGroup', 20),
            'priceIsFrom' => $s->bool('priceIsFrom'),
            'needsPatchTest' => $s->bool('needsPatchTest'),
            'depositAmount' => $s->money('depositAmount'),
            'options' => array_map(fn (SalonPayload $o) => [
                'productId' => $o->string('productId', 64, false),
                'label' => $o->string('label', 200),
                'price' => $o->money('price'),
                'durationMin' => $o->int('durationMin', 0, 1440),
                'byStylist' => array_map(fn (SalonPayload $b) => [
                    'userId' => $b->string('userId', 64, false),
                    'price' => $b->money('price'),
                    'durationMin' => $b->int('durationMin', 0, 1440),
                ], $o->objects('byStylist', 200)),
            ], $s->objects('options', 50)),
            'addOns' => array_map(fn (SalonPayload $a) => [
                'productId' => $a->string('productId', 64, false),
                'name' => $a->string('name', 200),
                'price' => $a->money('price'),
                'extraMin' => $a->int('extraMin', 0, 1440),
            ], $s->objects('addOns', 50)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function availability(SalonPayload $a): array
    {
        return [
            'fromDate' => $a->date('fromDate'),
            'dayCount' => $a->int('dayCount', 0, 400),
            'intervalMin' => $a->int('intervalMin', 1, 1440),
            'days' => array_map(fn (SalonPayload $d) => [
                'date' => $d->date('date'),
                'utcOffsetMinutes' => $d->int('utcOffsetMinutes', -720, 840),
                'items' => array_map(fn (SalonPayload $i) => [
                    'productId' => $i->string('productId', 64, false),
                    'stylists' => array_map(fn (SalonPayload $s) => [
                        'userId' => $s->string('userId', 64, false),
                        'ranges' => self::ranges($s),
                    ], $i->objects('stylists', 200)),
                ], $d->objects('items', 2000)),
            ], $a->objects('days', 400)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function ranges(SalonPayload $s): array
    {
        $ranges = $s->strings('ranges', 500, 11);

        foreach ($ranges as $i => $range) {
            if (preg_match(self::RANGE, $range, $m) !== 1 || ((int) $m[1] * 60 + (int) $m[2]) > ((int) $m[3] * 60 + (int) $m[4])) {
                throw SalonPayload::invalid($s->at('ranges')."[{$i}]", 'must be a range of start times such as 10:00-11:30');
            }
        }

        return $ranges;
    }
}
