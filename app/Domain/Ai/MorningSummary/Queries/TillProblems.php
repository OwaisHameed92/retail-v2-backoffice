<?php

namespace App\Domain\Ai\MorningSummary\Queries;

use App\Domain\Mail\Support\MailFormat;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Queries\UrgentSubjects;

/**
 * Till and sync problems still open this morning (module 6.3, from the Till health alerts of module 2.7), per shop,
 * with the alert type each belongs to so a user who already gets that type in the digest does not read it twice.
 */
final class TillProblems
{
    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, list<array{type: string, text: string}>>
     */
    public static function for(string $companyId, array $shops): array
    {
        $out = [];

        foreach (UrgentSubjects::open([$companyId])[$companyId] ?? [] as $subject) {
            $branch = (string) $subject->branchId;

            if (! isset($shops[$branch])) {
                continue;
            }

            $out[$branch][] = [
                'type' => $subject->type->value,
                'text' => $subject->type === AlertType::TillOffline
                    ? $subject->shopName.': '.($subject->tillName ?? 'a till').' offline since '.MailFormat::dateTime($subject->since)
                    : $subject->shopName.': sync '.($subject->problem === 'syncStalled' ? 'stalled' : 'failing').' since '.MailFormat::dateTime($subject->since),
            ];
        }

        return $out;
    }
}
