<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\AppVersion;
use Carbon\CarbonImmutable;

/**
 * The 200 replies of `licence/activate` (licence-activate-reply.schema.json) and `licence/validate`
 * (validate-reply.schema.json), contract v1.4.1 §17.15. The `licence` summary lists the token's fields; the
 * signed token always wins. Module 2.1: `apiKey` (+ `hubUrl`) is added by SyncKeyDelivery. Top-level
 * `companyId`/`branchId` are always the shop's ids as its tills know them, never blank (ShopTillIds,
 * ANSWERS-2026-10-06 "Purane khule sawal" 1): the till takes an `apiKey` only when they match its own database.
 */
final class LicenceReply
{
    public const TIMEZONE = 'Europe/London';

    public const DEFAULT_MINIMUM_APP_VERSION = '0.1.0';

    /**
     * @return array<string, mixed>
     */
    public function activation(Licence $licence, LicenceState $state, LicenceClaims $claims, string $status, string $token, CarbonImmutable $now): array
    {
        return [
            'status' => $status,
            'licenceToken' => $token,
            'licence' => self::summary($claims),
            ...ShopTillIds::for($licence),
            'portalTimeUtc' => ApiDate::format($now),
            'nextCheckAfterSeconds' => TillStatus::nextCheckAfterSeconds($status),
            'messages' => self::messages($licence, $state, $status, $claims->expiresAt, $now),
        ];
    }

    /**
     * @param  array{apiKey?: string, hubUrl?: string}  $link  module 2.1: the branch's sync key for the main till
     * @return array<string, mixed>
     */
    public function validation(Licence $licence, LicenceState $state, LicenceClaims $claims, string $status, ?string $token, CarbonImmutable $now, array $link = []): array
    {
        return [
            'status' => $status,
            'licenceToken' => $token,
            'licence' => self::summary($claims),
            ...ShopTillIds::for($licence),
            'apiKey' => $link['apiKey'] ?? null,
            ...(isset($link['hubUrl']) ? ['hubUrl' => $link['hubUrl']] : []),
            'minimumAppVersion' => self::minimumAppVersion(),
            'portalTimeUtc' => ApiDate::format($now),
            'nextCheckAfterSeconds' => TillStatus::nextCheckAfterSeconds($status),
            'messages' => self::messages($licence, $state, $status, $claims->expiresAt, $now),
        ];
    }

    /**
     * The key was released from this install (validate-reply.released.json).
     *
     * @return array<string, mixed>
     */
    public function released(CarbonImmutable $now): array
    {
        return [
            'status' => TillStatus::RELEASED,
            'licenceToken' => null,
            'licence' => null,
            'apiKey' => null,
            'minimumAppVersion' => self::minimumAppVersion(),
            'portalTimeUtc' => ApiDate::format($now),
            'nextCheckAfterSeconds' => TillStatus::nextCheckAfterSeconds(TillStatus::RELEASED),
            'messages' => [self::message('released', 'critical', 'Licence moved', 'This licence key was released on the portal. Enter a new key to keep selling.', $now, null, false)],
        ];
    }

    /**
     * The plain `licence` summary: the token's fields without the signature parts.
     *
     * @return array<string, mixed>
     */
    public static function summary(LicenceClaims $claims): array
    {
        $payload = $claims->toPayload('-', null);

        return [
            'licenceId' => $claims->licenceId,
            'kind' => $claims->kind->value,
            'source' => 'portal',
            'companyId' => $claims->companyId,
            'branchId' => $claims->branchId,
            'businessName' => $claims->businessName,
            'branchName' => $claims->branchName,
            'installCode' => $claims->installCode,
            'validFrom' => LicenceClaims::utc($claims->validFrom),
            'expiresAt' => LicenceClaims::utc($claims->expiresAt),
            'maxRegisters' => $claims->maxRegisters,
            'features' => $claims->features === [] ? null : $claims->features,
            'limits' => $claims->limits === [] ? null : $claims->limits,
            'company' => $payload['company'] ?? null,
        ];
    }

    /**
     * What the till shows the owner for this status (display only, §17.9).
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(Licence $licence, LicenceState $state, string $status, CarbonImmutable $expiresAt, CarbonImmutable $now): array
    {
        $day = self::day($expiresAt);
        $stamp = $expiresAt->format('Ymd');
        $reason = trim((string) $state->reason);

        $message = match (true) {
            $status === TillStatus::EXPIRING && $state->isTrial => self::message(
                "expiring-{$stamp}", 'warning', 'Trial ends soon', "Your free trial ends on {$day}. Choose a plan to keep trading.", null, $expiresAt, true,
            ),
            $status === TillStatus::EXPIRING => self::message(
                "expiring-{$stamp}", 'warning', 'Licence ends soon', "Your licence ends on {$day}. Renew it to keep trading. ".LicenceApiErrors::SUPPORT, null, $expiresAt, true,
            ),
            $status === TillStatus::EXPIRED && $state->status === LicenceStatus::Grace && $state->endsAt !== null => self::message(
                "expired-{$stamp}", 'critical', $state->isTrial ? 'Free trial ended' : 'Licence ended',
                'Your '.($state->isTrial ? 'free trial' : 'licence').' ended on '.self::day($state->endsAt).'. Renewal pending: the till starts trading again as soon as it is renewed. '.LicenceApiErrors::SUPPORT,
                $now, null, false,
            ),
            $status === TillStatus::EXPIRED => self::message(
                "expired-{$stamp}", 'critical', $state->isTrial ? 'Free trial ended' : 'Licence expired',
                ($reason !== '' ? $reason : 'The licence has expired.').' '.LicenceApiErrors::SUPPORT, $now, null, false,
            ),
            $status === TillStatus::SUSPENDED => self::message(
                'suspended-'.$licence->id, 'critical', 'Licence suspended', ($reason !== '' ? $reason : 'This licence is suspended.').' '.LicenceApiErrors::SUPPORT, $now, null, false,
            ),
            $status === TillStatus::REVOKED => self::message(
                'revoked-'.$licence->id, 'critical', 'Licence cancelled', ($reason !== '' ? $reason : 'This licence has been cancelled.').' '.LicenceApiErrors::SUPPORT, $now, null, false,
            ),
            default => null,
        };

        return $message === null ? [] : [$message];
    }

    /**
     * The oldest supported till version, sent in every validate reply (contract v1.4.1 §17.5; ANSWERS-2026-09-29
     * §3): informational only — the till keeps trading and we never answer 426 on licence/*. Default `0.1.0`
     * (tills send `0.1.x`); raise it only when the owner says so. An unreadable value falls back to the default.
     */
    public static function minimumAppVersion(): string
    {
        $minimum = trim((string) config('licence.api.minimum_app_version'));

        return AppVersion::isValid($minimum) ? $minimum : self::DEFAULT_MINIMUM_APP_VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $id, string $level, string $title, string $text, ?CarbonImmutable $from, ?CarbonImmutable $until, bool $dismissible): array
    {
        return [
            'id' => mb_substr($id, 0, 64),
            'level' => $level,
            'title' => mb_substr($title, 0, 80),
            'text' => mb_substr($text, 0, 500),
            'showFromUtc' => ApiDate::format($from),
            'showUntilUtc' => ApiDate::format($until),
            'dismissible' => $dismissible,
            'link' => null,
        ];
    }

    private static function day(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::TIMEZONE)->format('j F Y');
    }
}
