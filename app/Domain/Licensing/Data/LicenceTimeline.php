<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use Carbon\CarbonImmutable;

/**
 * The key dates of a licence in order, past and upcoming, for the licence page's timeline card.
 */
final class LicenceTimeline
{
    /**
     * @return list<array{key: string, label: string, detail: string|null, at: string, upcoming: bool, tone: string}>
     */
    public static function for(Licence $licence, CarbonImmutable $now): array
    {
        $state = LicenceState::for($licence, $now);
        $events = [];

        $add = function (string $key, string $label, ?CarbonImmutable $at, ?string $detail = null, string $tone = 'neutral') use (&$events, $now): void {
            if ($at !== null) {
                $events[] = ['key' => $key, 'label' => $label, 'detail' => $detail, 'at' => $at->utc()->toIso8601String(), 'upcoming' => $at->greaterThan($now), 'tone' => $tone, 'sort' => $at->getTimestamp()];
            }
        };

        $add('issued', 'Key issued', $licence->created_at, 'Plan '.($licence->plan->name ?? 'unknown'));
        $add('activated', 'First activated', $licence->activated_at, $licence->device_name, 'success');

        if ($licence->bound_at !== null && ($licence->activated_at === null || ! $licence->bound_at->equalTo($licence->activated_at))) {
            $add('bound', 'Bound to a PC', $licence->bound_at, $licence->device_name ?? $licence->device_id, 'info');
        }

        if ($licence->trial_ends_at !== null) {
            $add('trial_ends', $licence->trial_ends_at->greaterThan($now) ? 'Trial ends' : 'Trial ended', $licence->trial_ends_at, null, 'info');
        }

        if ($licence->expires_at !== null) {
            $add('expires', $licence->expires_at->greaterThan($now) ? 'Paid until' : 'Paid period ended', $licence->expires_at, null, 'success');
        }

        if ($state->graceEndsAt !== null && $licence->grace_days > 0 && ! $licence->isRevoked()) {
            $upcoming = $state->graceEndsAt->greaterThan($now);
            $add('grace_ends', $upcoming ? 'Till locks if not renewed' : 'Grace ended', $state->graceEndsAt, $licence->grace_days.' grace '.($licence->grace_days === 1 ? 'day' : 'days'), 'warning');
        }

        $add('suspended', 'Suspended', $licence->suspended_at, $licence->suspended_reason, 'danger');
        $add('revoked', 'Revoked', $licence->revoked_at, $licence->revoked_reason, 'danger');
        $add('check_in', 'Last check-in', $licence->last_check_in_at, $licence->last_app_version ? 'SSPOS '.$licence->last_app_version : null, 'neutral');

        usort($events, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(function (array $event) {
            unset($event['sort']);

            return $event;
        }, $events);
    }
}
