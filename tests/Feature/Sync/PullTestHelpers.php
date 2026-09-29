<?php

namespace Tests\Feature\Sync;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Values;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.5 test helpers: portal-side saves of hub-owned rows, valid till payloads, reply shortcuts. */
final class PullTestHelpers
{
    /**
     * Saves a hub-owned row on the portal through its model (HubOwnedRow), from a till-shaped payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $columns  extra column values (e.g. branch_id)
     */
    public static function portalCreate(Company $company, string $entity, array $payload, array $columns = []): Model
    {
        $def = EntityRegistry::get($entity);
        $attributes = ['id' => $payload['id']];

        foreach ($def->fields as $name => $field) {
            if (array_key_exists($name, $payload)) {
                $attributes[$field->column] = Values::toColumn($field, $payload[$name]);
            }
        }

        return app(CurrentCompany::class)->runAs($company, function () use ($def, $attributes, $columns) {
            $model = new ($def->model);
            $model->forceFill([...$attributes, ...$columns])->save();

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function portalUpdate(Company $company, string $entity, string $id, array $attributes): void
    {
        $class = EntityRegistry::get($entity)->model;
        app(CurrentCompany::class)->runAs($company, fn () => $class::query()->findOrFail($id)->forceFill($attributes)->save());
    }

    public static function portalDelete(Company $company, string $entity, string $id): void
    {
        $class = EntityRegistry::get($entity)->model;
        app(CurrentCompany::class)->runAs($company, fn () => $class::query()->findOrFail($id)->delete());
    }

    /**
     * A schema-valid till payload of any entity (non-null members get a plain value of their type).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(string $entity, string $id, array $overrides = []): array
    {
        $payload = [];

        foreach (EntityRegistry::get($entity)->fields as $name => $field) {
            $payload[$name] = $field->nullable ? null : match ($field->type) {
                'enum' => ($field->enumClass())::cases()[0]->value,
                'int', 'bigint' => 1,
                'bool' => false,
                'money', 'cost', 'quantity', 'percent', 'rate' => 1.5,
                'date' => '2026-09-23',
                'time' => '09:00:00',
                'datetime' => '2026-09-23T09:41:12Z',
                'json' => [],
                default => 'x',
            };
        }

        return [
            ...$payload, 'id' => $id, 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-23T09:41:12Z',
            'updatedAt' => '2026-09-23T09:41:12Z', 'rowVersion' => 1, 'deletedAt' => null, ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function changes(TestResponse $reply): array
    {
        return $reply->json('changes');
    }

    /**
     * [entity, op, version] of each change.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function summary(TestResponse $reply): array
    {
        return array_map(fn (array $c) => [$c['entity'], $c['op'], $c['version']], self::changes($reply));
    }
}
