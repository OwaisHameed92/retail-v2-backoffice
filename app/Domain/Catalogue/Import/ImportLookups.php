<?php

namespace App\Domain\Catalogue\Import;

use App\Domain\Catalogue\Actions\SaveCategory;
use App\Domain\Catalogue\Actions\SaveDepartment;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\VatRate;

/**
 * The current company's departments, top-level categories and VAT rates by what a CSV calls them (names ignore case;
 * VAT by code or percentage), loaded once per preview or chunk. Missing departments and categories are created on
 * apply through SaveDepartment / SaveCategory (hub-owned: every till receives them) and remembered.
 */
final class ImportLookups
{
    /** @var array<string, string> lower name → id */
    private array $departments = [];

    /** @var array<string, string> "departmentId|lower name" → id */
    private array $categories = [];

    /** @var array<string, string> category id → department id */
    private array $categoryDepartment = [];

    /** @var array<string, string|null> department or category id → default VAT rate id */
    private array $defaultVat = [];

    /** @var array<string, string> lower code or "20.0000" → id */
    private array $vat = [];

    private ?string $companyVat = null;

    public static function load(): self
    {
        $lookups = new self;

        foreach (Department::query()->get(['id', 'name', 'default_vat_rate_id']) as $d) {
            $lookups->departments[mb_strtolower((string) $d->name)] ??= $d->id;
            $lookups->defaultVat[$d->id] = $d->default_vat_rate_id;
        }

        foreach (Category::query()->whereNull('parent_category_id')->get(['id', 'name', 'department_id', 'default_vat_rate_id']) as $c) {
            $lookups->categories[$c->department_id.'|'.mb_strtolower((string) $c->name)] ??= $c->id;
            $lookups->categoryDepartment[$c->id] = $c->department_id;
            $lookups->defaultVat[$c->id] = $c->default_vat_rate_id;
        }

        foreach (VatRate::query()->orderByDesc('is_default')->get(['id', 'code', 'percentage', 'is_default']) as $v) {
            $lookups->vat[mb_strtolower((string) $v->code)] ??= $v->id;
            $lookups->vat[(string) $v->percentage] ??= $v->id;
            $lookups->companyVat ??= $v->is_default ? $v->id : null;
        }

        return $lookups;
    }

    public function departmentId(?string $name): ?string
    {
        return $name === null ? null : ($this->departments[mb_strtolower($name)] ?? null);
    }

    public function categoryId(?string $departmentId, ?string $name): ?string
    {
        return $departmentId === null || $name === null ? null : ($this->categories[$departmentId.'|'.mb_strtolower($name)] ?? null);
    }

    public function departmentOfCategory(string $categoryId): ?string
    {
        return $this->categoryDepartment[$categoryId] ?? null;
    }

    /** A VAT rate by code ("S") or percentage ("20", "20%", "20.0"); null when the business has no such rate. */
    public function vatId(string $value): ?string
    {
        $value = mb_strtolower(trim(str_replace('%', '', $value)));

        if (isset($this->vat[$value])) {
            return $this->vat[$value];
        }

        return is_numeric($value) ? ($this->vat[number_format((float) $value, 4, '.', '')] ?? null) : null;
    }

    /** VAT for a new product without one: its category's default, its department's, then the business default. */
    public function defaultVat(?string $departmentId, ?string $categoryId): ?string
    {
        return ($categoryId !== null ? $this->defaultVat[$categoryId] ?? null : null)
            ?? ($departmentId !== null ? $this->defaultVat[$departmentId] ?? null : null)
            ?? $this->companyVat;
    }

    public function ensureDepartment(string $name, SaveDepartment $save): string
    {
        return $this->departmentId($name) ?? $this->departments[mb_strtolower($name)] = $save->handle(null, ['name' => $name])->id;
    }

    public function ensureCategory(string $departmentId, string $name, SaveCategory $save): string
    {
        $id = $this->categoryId($departmentId, $name);

        if ($id === null) {
            $id = $save->handle(null, ['department_id' => $departmentId, 'name' => $name, 'parent_category_id' => null])->id;
            $this->categories[$departmentId.'|'.mb_strtolower($name)] = $id;
            $this->categoryDepartment[$id] = $departmentId;
        }

        return $id;
    }
}
