<?php

namespace App\Domain\Setup\Queries;

use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Setup\Support\PaymentTypeGroup;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\ReasonType;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Reason;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Props for the payment types and reasons screens (module 4.5): searchable, sortable, paged lists edited in a
 * dialog. Runs inside the company scope.
 */
final class TenderAndReasonLists
{
    /**
     * One line per name (PaymentTypeGroup): the tills make some types once per shop. `shops` says how many rows the
     * line stands for, `mixed` that they differ (a change is made to each), `system` that it is the till's own.
     *
     * @return array<string, mixed>
     */
    public static function paymentTypes(Request $request): array
    {
        $table = TableQuery::from($request)->searchable(['name'])->sortable(['position', 'name'])->defaultSort('position');
        $groups = PaymentType::query()->orderBy('id')->get()->groupBy(fn (PaymentType $t) => PaymentTypeGroup::key($t->name));
        $flags = fn (PaymentType $row): array => $row->only(['position', ...SavePaymentType::FLAGS]);

        return [
            'counts' => ['all' => $groups->count(), 'active' => $groups->filter(fn ($group) => (bool) $group->first()?->is_active)->count()],
            'canEdit' => self::canEdit(),
            'paymentTypes' => $table->paginate(PaymentTypeGroup::leaders(), function (PaymentType $t) use ($groups, $flags): array {
                $group = $groups->get(PaymentTypeGroup::key($t->name)) ?? collect([$t]);

                return [
                    'id' => $t->id, 'name' => $t->name, 'position' => (int) $t->position, 'kind' => self::tenderKind($t),
                    'is_cash' => (bool) $t->is_cash, 'is_card' => (bool) $t->is_card, 'is_voucher' => (bool) $t->is_voucher,
                    'is_points' => (bool) $t->is_points, 'is_account' => (bool) $t->is_account, 'is_drs_refund' => (bool) $t->is_drs_refund,
                    'opens_drawer' => (bool) $t->opens_drawer, 'show_on_payment' => (bool) $t->show_on_payment,
                    'show_on_refund' => (bool) $t->show_on_refund, 'show_on_customer_payment' => (bool) $t->show_on_customer_payment,
                    'is_active' => (bool) $t->is_active,
                    'shops' => $group->count(),
                    'mixed' => $group->contains(fn (PaymentType $row) => $flags($row) !== $flags($t)),
                    'system' => PaymentTypeGroup::isTillSystem($t->name),
                ];
            }),
        ];
    }

    /** @return array<string, mixed> */
    public static function reasons(Request $request): array
    {
        $type = ReasonType::tryFrom((string) $request->query('type'));
        $table = TableQuery::from($request)->searchable(['text', 'account_code'])->sortable(['type', 'position', 'text']);

        return [
            'filters' => ['type' => $type?->value],
            'counts' => ['all' => Reason::query()->count(), 'active' => Reason::query()->where('is_active', true)->count()],
            'types' => array_map(fn (ReasonType $t) => ['value' => $t->value, 'label' => self::reasonTypeLabel($t)], ReasonType::cases()),
            'canEdit' => self::canEdit(),
            'reasons' => $table->paginate(
                Reason::query()->when($type !== null, fn ($q) => $q->where('type', $type?->value))
                    ->when($table->sort() === null, fn ($q) => $q->orderBy('type')->orderBy('position')),
                fn (Reason $r) => [
                    'id' => $r->id, 'type' => $r->type?->value, 'typeLabel' => $r->type === null ? 'Other' : self::reasonTypeLabel($r->type),
                    'text' => $r->text, 'position' => (int) $r->position, 'is_active' => (bool) $r->is_active, 'account_code' => $r->account_code,
                ],
            ),
        ];
    }

    public static function reasonTypeLabel(ReasonType $type): string
    {
        return match ($type) {
            ReasonType::NoSale => 'No sale',
            ReasonType::PaidIn => 'Paid in',
            ReasonType::PaidOut => 'Paid out',
            ReasonType::StockAdjust => 'Stock adjustment',
            ReasonType::AgeRefusal => 'Age check refusal',
            ReasonType::OrderCancel => 'Order cancelled',
            ReasonType::CashReconciliation => 'Cash reconciliation',
            default => Str::ucfirst(Str::lower(Str::headline($type->value))),
        };
    }

    private static function tenderKind(PaymentType $t): string
    {
        return match (true) {
            (bool) $t->is_cash => 'Cash',
            (bool) $t->is_card => 'Card',
            (bool) $t->is_voucher => 'Voucher',
            (bool) $t->is_points => 'Loyalty points',
            (bool) $t->is_account => 'Customer account',
            (bool) $t->is_drs_refund => 'Deposit return',
            default => 'Other',
        };
    }

    private static function canEdit(): bool
    {
        return app(CurrentCompany::class)->restrictedBranchId() === null;
    }
}
