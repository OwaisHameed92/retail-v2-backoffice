<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The success replies of `activate` and `check-in` (docs/specs/licence-api-v1.md, "Endpoints"). Everything is
 * built from the licence's own company, branch and till: a key never reaches another company's rows.
 */
final class LicenceReply
{
    public const TIMEZONE = 'Europe/London';

    public function __construct(
        private readonly LicenceToken $token,
        private readonly Config $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function activation(Licence $licence, CarbonImmutable $now): array
    {
        $licence->loadMissing(['company', 'branch', 'register', 'plan']);
        $state = LicenceState::for($licence, $now);

        return [
            'licence' => $this->licence($licence, $state),
            'token' => $this->token->for($licence, $state, $now),
            'company' => $licence->company === null ? null : TillEntities::company($licence->company),
            'branch' => $licence->branch === null ? null : TillEntities::branch($licence->branch),
            'register' => $licence->register === null ? null : TillEntities::register($licence->register),
            // Module 2.1 fills {hubUrl, apiKey} for a main (or single) till; null until then.
            'sync' => null,
            'checkInEverySeconds' => $this->checkInEverySeconds(),
            'serverTimeUtc' => ApiDate::format($now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkIn(Licence $licence, CarbonImmutable $now): array
    {
        $licence->loadMissing(['company', 'branch', 'register', 'plan']);
        $state = LicenceState::for($licence, $now);

        return [
            'licence' => $this->licence($licence, $state),
            'token' => $this->token->for($licence, $state, $now),
            'message' => self::message($state),
            'checkInEverySeconds' => $this->checkInEverySeconds(),
            'serverTimeUtc' => ApiDate::format($now),
        ];
    }

    /**
     * The `licence` object of a reply.
     *
     * @return array<string, mixed>
     */
    public function licence(Licence $licence, LicenceState $state): array
    {
        return [
            'id' => $licence->id,
            'keyLast4' => $licence->key_last4,
            'status' => $state->status->value,
            'plan' => $licence->plan?->code,
            'features' => LicenceToken::features($licence),
            'activatedAt' => ApiDate::format($licence->activated_at),
            'trialEndsAt' => ApiDate::format($licence->trial_ends_at),
            'expiresAt' => ApiDate::format($licence->expires_at),
            'graceDays' => max(0, $licence->grace_days),
            'deviceId' => $licence->device_id,
        ];
    }

    /**
     * en-GB line for the till to show, or null when there is nothing to say (paid and trading).
     */
    public static function message(LicenceState $state): ?string
    {
        $what = $state->isTrial ? 'free trial' : 'licence';

        return match ($state->status) {
            LicenceStatus::Trial => 'Free trial until '.self::day($state->endsAt).'.',
            LicenceStatus::Active, LicenceStatus::Issued => null,
            LicenceStatus::Grace => 'Your '.$what.' ended on '.self::day($state->endsAt).'. The till will stop taking sales on '
                .self::day($state->graceEndsAt).' unless it is renewed. '.LicenceApiErrors::SUPPORT,
            default => trim(($state->reason ?? 'This licence cannot be used.').' '.LicenceApiErrors::SUPPORT),
        };
    }

    private function checkInEverySeconds(): int
    {
        return max(60, (int) $this->config->get('licence.check_in_seconds', 86400));
    }

    private static function day(?CarbonImmutable $at): string
    {
        return $at === null ? 'an unknown date' : $at->setTimezone(self::TIMEZONE)->format('j F Y');
    }
}
