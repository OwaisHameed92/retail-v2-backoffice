<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Before/after values of a model's unsaved changes, for RecordAudit. Call before save().
 * An empty "after" means nothing really changed.
 */
final class AuditChanges
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function of(Model $model): array
    {
        $original = $model->getRawOriginal();
        $dirty = $model->getDirty();
        unset($dirty['updated_at']);

        // A column never loaded on this instance (e.g. just created) that is set to null is not a change.
        $dirty = array_filter(
            $dirty,
            fn (mixed $value, string $key) => $value !== null || array_key_exists($key, $original),
            ARRAY_FILTER_USE_BOTH,
        );

        $before = array_intersect_key($original, $dirty);

        return [$before, $dirty];
    }
}
