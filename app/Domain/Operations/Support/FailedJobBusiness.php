<?php

namespace App\Domain\Operations\Support;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Best effort: which business a failed job was about, from its payload. A queued Company wins, then a `companyId`
 * property, then the `company_id` of the first queued tenant-owned model. Never throws; unknown → null.
 */
final class FailedJobBusiness
{
    /**
     * @param  array<string, FailedJobPayload>  $payloads  key => payload
     * @return array<string, array{id: string, name: string}> key => business
     */
    public static function resolve(array $payloads): array
    {
        $companyIds = [];

        foreach ($payloads as $key => $payload) {
            try {
                $companyId = self::companyIdOf($payload);
            } catch (Throwable) {
                $companyId = null;
            }

            if ($companyId !== null) {
                $companyIds[$key] = $companyId;
            }
        }

        if ($companyIds === []) {
            return [];
        }

        $names = Company::query()->withTrashed()->whereIn('id', array_values(array_unique($companyIds)))->pluck('name', 'id')->all();
        $businesses = [];

        foreach ($companyIds as $key => $id) {
            if (isset($names[$id])) {
                $businesses[$key] = ['id' => $id, 'name' => (string) $names[$id]];
            }
        }

        return $businesses;
    }

    private static function companyIdOf(FailedJobPayload $payload): ?string
    {
        $models = $payload->models();

        foreach ($models as $model) {
            if ($model['class'] === Company::class) {
                return $model['id'];
            }
        }

        if (($id = $payload->companyIdProperty()) !== null) {
            return $id;
        }

        foreach ($models as $model) {
            $class = $model['class'];

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $class;
            $companyId = $instance->newQueryWithoutScopes()->whereKey($model['id'])->value('company_id');

            if (is_string($companyId) && $companyId !== '') {
                return $companyId;
            }
        }

        return null;
    }
}
