<?php

namespace App\Domain\Demo\Support;

use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The guards that make demo businesses (`companies.is_demo`, made by `demo:billing`) safe on a live server:
 *
 * - GoCardless: a demo business's mandate, subscription and payment ids start with {@see self::ID_PREFIX} and are
 *   never sent to GoCardless (DemoSafeGoCardlessClient refuses them, and the Direct Debit actions skip or refuse
 *   demo businesses before they get that far);
 * - email: nothing is sent to a demo business or about one (BrandedMailable logs it as "suppressed"), nor to an
 *   address on the reserved `.invalid` domain.
 */
final class DemoBusinesses
{
    /** Every GoCardless-style id a demo business gets (mandate, subscription, payment, customer). */
    public const ID_PREFIX = 'DEMO-';

    /** Reserved by RFC 2606: never delivered anywhere. Demo owners and companies use it. */
    public const EMAIL_DOMAIN = 'example.invalid';

    /** Name prefix of every demo business. */
    public const NAME_PREFIX = 'DEMO – ';

    public static function isDemo(Company|string|null $company): bool
    {
        if ($company instanceof Company) {
            return (bool) $company->is_demo;
        }

        if ($company === null || $company === '') {
            return false;
        }

        return (bool) Company::withTrashed()->whereKey($company)->value('is_demo');
    }

    /**
     * Leaves demo businesses' rows out of a query across tenants (soft-deleted businesses stay in): money figures
     * count real money only (audit BILL-4).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeFrom(Builder $query, string $column = 'company_id'): Builder
    {
        return $query->whereNotIn($query->qualifyColumn($column), Company::withTrashed()->where('is_demo', true)->select('id'));
    }

    /** A GoCardless id made up for a demo business. */
    public static function isDemoId(?string $id): bool
    {
        return $id !== null && str_starts_with($id, self::ID_PREFIX);
    }

    /** An address that can never receive mail (`something.invalid`). */
    public static function isUndeliverable(string $email): bool
    {
        return str_ends_with(mb_strtolower(trim($email)), '.invalid');
    }

    /** @throws GoCardlessException */
    public static function refuseGoCardless(Company $company): void
    {
        if (self::isDemo($company)) {
            throw new GoCardlessException(self::goCardlessMessage());
        }
    }

    public static function goCardlessMessage(): string
    {
        return 'This is a demo business: nothing is ever sent to GoCardless for it.';
    }
}
