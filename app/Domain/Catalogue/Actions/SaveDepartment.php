<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a department (hub-owned: every till receives it). Names are unique in the business (an import
 * finds departments by name). Keeps the department's ULID.
 */
final class SaveDepartment
{
    public const FIELDS = ['name', 'position', 'colour_hex', 'is_active', 'is_visible_on_till', 'show_in_report', 'default_vat_rate_id'];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(?Department $department, array $attributes): Department
    {
        $attributes = Arr::only($attributes, self::FIELDS);
        $created = $department === null;
        $department ??= (new Department)->forceFill([
            'position' => (int) Department::query()->max('position') + 1, 'colour_hex' => '#1F6FEB', 'is_active' => true,
            'is_visible_on_till' => true, 'show_in_report' => true,
        ]);
        $name = trim((string) ($attributes['name'] ?? $department->name));

        if (Department::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when(! $created, fn ($q) => $q->whereKeyNot($department->id))->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a department called {$name}."]);
        }

        $vat = $attributes['default_vat_rate_id'] ?? null;

        if ($vat !== null && ! VatRate::query()->whereKey($vat)->exists()) {
            throw ValidationException::withMessages(['default_vat_rate_id' => Country::tax('Choose a VAT rate.')]);
        }

        $department->forceFill([...$attributes, 'name' => $name]);

        if ($created || $department->isDirty()) {
            if (! $created) {
                $department->row_version = (int) $department->row_version + 1;
            }

            $department->save();
            $this->audit->handle($created ? 'department.created' : 'department.updated', $department, null, ['name' => $department->name], ['name' => $department->name]);
        }

        return $department;
    }
}
