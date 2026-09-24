<?php

namespace App\Domain\Leads\Support;

use App\Domain\Leads\Data\DuplicateMatch;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Possible duplicates of a lead: other leads, and tenants whose business email, business phone or portal users
 * share the lead's email or phone number. Only a warning: staff decide.
 */
final class LeadDuplicates
{
    /**
     * @return list<DuplicateMatch>
     */
    public static function for(Lead $lead): array
    {
        $email = $lead->email === null ? null : mb_strtolower($lead->email);
        $phone = PhoneDigits::from($lead->phone);

        if ($email === null && $phone === null) {
            return [];
        }

        return array_merge(self::leads($lead, $email, $phone), self::tenants($lead, $email, $phone));
    }

    /**
     * @return list<DuplicateMatch>
     */
    private static function leads(Lead $lead, ?string $email, ?string $phone): array
    {
        return Lead::query()
            ->whereKeyNot($lead->id)
            ->where(function (Builder $q) use ($email, $phone) {
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_digits', $phone);
                }
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Lead $other) => new DuplicateMatch(
                type: 'lead',
                id: $other->id,
                name: $other->business_name,
                status: $other->status->value,
                matchedOn: self::matched($email !== null && $other->email === $email, $phone !== null && $other->phone_digits === $phone),
                createdAt: $other->created_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<DuplicateMatch>
     */
    private static function tenants(Lead $lead, ?string $email, ?string $phone): array
    {
        /** @var Collection<string, array{company: Company, email: bool, phone: bool}> $found */
        $found = collect();

        $remember = function (Company $company, bool $byEmail, bool $byPhone) use (&$found): void {
            $current = $found->get($company->id, ['company' => $company, 'email' => false, 'phone' => false]);
            $found->put($company->id, ['company' => $company, 'email' => $current['email'] || $byEmail, 'phone' => $current['phone'] || $byPhone]);
        };

        if ($email !== null) {
            Company::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($lead->company_id ?? '')->limit(10)->get()
                ->each(fn (Company $company) => $remember($company, true, false));

            $user = User::query()->where('email', $email)->first();
            $user?->companies()->whereKeyNot($lead->company_id ?? '')->limit(10)->get()
                ->each(fn (Company $company) => $remember($company, true, false));
        }

        if ($phone !== null) {
            // Phone numbers are stored as typed: narrow by the last 3 digits in SQL, compare exactly in PHP.
            Company::query()->whereNotNull('phone')->where('phone', 'like', '%'.substr($phone, -3).'%')
                ->whereKeyNot($lead->company_id ?? '')->limit(200)->get()
                ->filter(fn (Company $company) => PhoneDigits::from($company->phone) === $phone)
                ->each(fn (Company $company) => $remember($company, false, true));
        }

        return $found->values()->map(fn (array $hit) => new DuplicateMatch(
            type: 'tenant',
            id: $hit['company']->id,
            name: $hit['company']->name,
            status: $hit['company']->status->value,
            matchedOn: self::matched($hit['email'], $hit['phone']),
            createdAt: $hit['company']->created_at,
        ))->all();
    }

    /**
     * @return list<string>
     */
    private static function matched(bool $email, bool $phone): array
    {
        return array_values(array_filter([$email ? 'email' : null, $phone ? 'phone' : null]));
    }
}
