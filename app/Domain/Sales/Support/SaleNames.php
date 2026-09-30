<?php

namespace App\Domain\Sales\Support;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\TillUser;

/**
 * Names for the ids a sale carries (shop, till, staff, customer, reason), looked up once per page in the current
 * company's scope. An id the portal does not know shows as null (the screen says "Unknown").
 */
final class SaleNames
{
    /** @var array<string, string> */
    public array $shops = [];

    /** @var array<string, string> */
    public array $tills = [];

    /** @var array<string, string> */
    public array $staff = [];

    /** @var array<string, array{name: string, cardNo: string|null}> */
    public array $customers = [];

    /** @var array<string, string> */
    public array $reasons = [];

    /**
     * @param  iterable<object>  $sales  rows with branch_id, register_id, user_id, customer_id (and optionally more)
     * @param  list<string|null>  $extraStaff  more till user ids (approvers, voiders, audit users)
     * @param  list<string|null>  $reasonIds
     */
    public static function for(iterable $sales, array $extraStaff = [], array $reasonIds = []): self
    {
        $ids = ['branch' => [], 'register' => [], 'user' => $extraStaff, 'customer' => []];

        foreach ($sales as $sale) {
            foreach (['branch', 'register', 'user', 'customer'] as $kind) {
                $value = $sale->{$kind.'_id'} ?? null;

                if (is_string($value) && $value !== '') {
                    $ids[$kind][] = $value;
                }
            }
        }

        $names = new self;
        $unique = fn (array $list) => array_values(array_unique(array_filter($list, fn ($v) => is_string($v) && $v !== '')));

        $names->shops = self::pluck(Branch::query()->withTrashed()->whereKey($unique($ids['branch']))->pluck('name', 'id')->all());
        $names->tills = Register::query()->withTrashed()->whereKey($unique($ids['register']))->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Register $r) => [$r->id => $r->name !== '' ? $r->name : 'Till '.$r->code])->all();
        $names->staff = self::pluck(TillUser::query()->withTrashed()->whereKey($unique($ids['user']))->pluck('name', 'id')->all());
        $names->customers = Customer::query()->withTrashed()->whereKey($unique($ids['customer']))->get(['id', 'name', 'card_no'])
            ->mapWithKeys(fn (Customer $c) => [$c->id => ['name' => $c->name ?: 'Unnamed customer', 'cardNo' => $c->card_no ?: null]])->all();
        $names->reasons = $reasonIds === [] ? [] : self::pluck(Reason::query()->withTrashed()->whereKey($unique($reasonIds))->pluck('text', 'id')->all());

        return $names;
    }

    public function shop(?string $id): ?string
    {
        return $id !== null ? ($this->shops[$id] ?? null) : null;
    }

    public function till(?string $id): ?string
    {
        return $id !== null ? ($this->tills[$id] ?? null) : null;
    }

    public function person(?string $id): ?string
    {
        return $id !== null && $id !== '' ? ($this->staff[$id] ?? null) : null;
    }

    public function reason(?string $id): ?string
    {
        return $id !== null && $id !== '' ? ($this->reasons[$id] ?? null) : null;
    }

    /** @return array{id: string, name: string, cardNo: string|null}|null */
    public function customer(?string $id): ?array
    {
        return $id !== null && isset($this->customers[$id]) ? ['id' => $id, ...$this->customers[$id]] : null;
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<string, string>
     */
    private static function pluck(array $rows): array
    {
        $out = [];

        foreach ($rows as $id => $name) {
            $out[(string) $id] = (string) $name;
        }

        return $out;
    }
}
