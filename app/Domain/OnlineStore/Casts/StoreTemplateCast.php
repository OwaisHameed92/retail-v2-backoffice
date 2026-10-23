<?php

namespace App\Domain\OnlineStore\Casts;

use App\Domain\OnlineStore\Enums\StoreTemplate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `online_stores.template` as a StoreTemplate. Unlike a plain enum cast, a stored value that is no longer a template
 * (the removed `pharmacy`, or anything typed into the database) reads as Fresh Market instead of failing the page.
 *
 * @implements CastsAttributes<StoreTemplate, StoreTemplate|string>
 */
final class StoreTemplateCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): StoreTemplate
    {
        return StoreTemplate::fromStored($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value instanceof StoreTemplate ? $value->value : StoreTemplate::from((string) $value)->value;
    }
}
