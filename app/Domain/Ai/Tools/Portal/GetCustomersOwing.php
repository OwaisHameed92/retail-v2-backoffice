<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Read: customer accounts that owe money (module 4.4: `customers.balance` > 0, kept equal to the ledger by the
 * portal), largest first. Only the name, card number and amounts: no phone, email or address (PII minimised). With a
 * shop (always, for a one-shop user): customers with account activity at that shop, as the customers list filter.
 */
final class GetCustomersOwing extends PortalReadTool
{
    public function name(): string
    {
        return 'get_customers_owing';
    }

    public function description(): string
    {
        return 'Customer accounts that owe money now (account / tab customers): how many, the total owed, and the '
            .'largest balances with credit limit and whether they are over it. Optionally only those over their limit.';
    }

    public function inputSchema(): array
    {
        return self::object([
            'over_limit_only' => ['type' => 'boolean', 'description' => 'Only customers over their credit limit.'],
            'shop_id' => ShopPin::schema(),
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'Default 10.'],
        ]);
    }

    public function rules(): array
    {
        return [
            'over_limit_only' => ['nullable', 'boolean'],
            'shop_id' => ['nullable', 'string', new ValidUlid],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::CustomersView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $overLimit = (bool) ($input['over_limit_only'] ?? false);

        $query = Customer::query()
            ->where('balance', '>', 0)
            ->when($overLimit, fn (Builder $q) => $q->where('credit_limit', '>', 0)->whereColumn('balance', '>', 'credit_limit'))
            ->when($shop->id !== null, fn (Builder $q) => $q->whereExists(fn (QueryBuilder $e) => $e->from('customer_transactions as t')
                ->whereColumn('t.company_id', 'customers.company_id')->where('t.branch_id', $shop->id)
                ->whereColumn('t.customer_id', 'customers.id')->whereNull('t.deleted_at')));

        $count = (clone $query)->count();
        $total = Money::sum((clone $query)->pluck('balance')->map(fn (mixed $b) => (string) $b)->all());
        $customers = $query->orderByDesc('balance')->orderBy('id')->limit((int) ($input['limit'] ?? 10))
            ->get(['id', 'name', 'card_no', 'balance', 'credit_limit']);

        $this->links->add('Customers who owe · '.$shop->name, '/app/customers', [
            'balance' => $overLimit ? 'overLimit' : 'owes', 'sort' => 'balance', 'direction' => 'desc', 'shop' => $shop->id,
        ], $shop);

        return [
            ...$shop->toArray(),
            'customersOwing' => $count,
            'totalOwed' => $total,
            'largest' => $customers->map(fn (Customer $c) => [
                'customerId' => $c->id,
                'name' => $c->name ?: 'Unnamed customer',
                'cardNo' => $c->card_no ?: null,
                'owes' => Money::normalise($c->balance),
                'creditLimit' => Money::isZero($c->credit_limit ?? '0') ? null : Money::normalise($c->credit_limit),
                'overLimit' => ! Money::isZero($c->credit_limit ?? '0') && Money::compare($c->balance, $c->credit_limit) > 0,
            ])->values()->all(),
        ];
    }
}
