<?php

namespace App\Domain\Setup\Queries;

use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\SupplierOrderMethod;
use App\Domain\TillData\Enums\SupplierTermsKind;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Http\Request;

/**
 * Props for the suppliers screens (module 4.5): the list (search, status filter, sorting, paging) and the form.
 * Runs inside the company scope: another business's suppliers are never read.
 */
final class SupplierList
{
    /** @return array<string, mixed> */
    public static function index(Request $request): array
    {
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : 'all';
        $table = TableQuery::from($request)
            ->searchable(['name', 'code', 'contact_name', 'phone', 'email', 'town', 'postcode', 'account_number'])
            ->sortable(['name', 'code', 'town', 'updated_at'])->defaultSort('name');

        return [
            'filters' => ['status' => $status],
            'counts' => [
                'all' => Supplier::query()->count(),
                'active' => Supplier::query()->where('is_active', true)->count(),
                'inactive' => Supplier::query()->where('is_active', false)->count(),
            ],
            'canEdit' => app(CurrentCompany::class)->restrictedBranchId() === null,
            'suppliers' => $table->paginate(
                Supplier::query()->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active')),
                fn (Supplier $s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'contactName' => $s->contact_name,
                    'phone' => $s->phone, 'email' => $s->email, 'town' => $s->town, 'isActive' => (bool) $s->is_active,
                    'terms' => self::termsLabel($s->terms_kind, $s->payment_terms_days),
                    'orderMethod' => $s->order_method === null ? null : self::orderMethodLabel($s->order_method),
                    'deliveryDays' => $s->delivery_days ?: null,
                    'updatedAt' => $s->updated_at?->toIso8601ZuluString(),
                ],
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function form(?Supplier $s): array
    {
        return [
            'supplier' => $s === null ? null : [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'is_active' => (bool) $s->is_active,
                'contact_name' => $s->contact_name ?? '', 'phone' => $s->phone ?? '', 'email' => $s->email ?? '',
                'address_line1' => $s->address_line1 ?? '', 'address_line2' => $s->address_line2 ?? '', 'town' => $s->town ?? '',
                'postcode' => $s->postcode ?? '', 'account_number' => $s->account_number ?? '', 'vat_number' => $s->vat_number ?? '',
                'terms_kind' => ($s->terms_kind ?? SupplierTermsKind::OnDelivery)->value,
                'payment_terms_days' => (string) ($s->payment_terms_days ?? 0), 'default_lead_days' => (string) ($s->default_lead_days ?? 0),
                'minimum_order_value' => (string) ($s->minimum_order_value ?? '0.00'),
                'order_method' => ($s->order_method ?? SupplierOrderMethod::Phone)->value,
                'delivery_days' => array_values(array_filter(array_map('trim', explode(',', strtolower($s->delivery_days ?? ''))))),
                'notes' => $s->notes ?? '', 'updatedAt' => $s->updated_at?->toIso8601ZuluString(),
            ],
            'options' => [
                'termsKinds' => array_map(fn (SupplierTermsKind $k) => ['value' => $k->value, 'label' => self::termsLabel($k, null)], SupplierTermsKind::cases()),
                'orderMethods' => array_map(fn (SupplierOrderMethod $m) => ['value' => $m->value, 'label' => self::orderMethodLabel($m)], SupplierOrderMethod::cases()),
                'days' => SaveSupplier::DAYS,
            ],
            'canEdit' => app(CurrentCompany::class)->restrictedBranchId() === null,
        ];
    }

    public static function termsLabel(?SupplierTermsKind $kind, ?int $days): ?string
    {
        return match ($kind) {
            SupplierTermsKind::Prepay => 'Pay before delivery',
            SupplierTermsKind::OnDelivery => 'Pay on delivery',
            SupplierTermsKind::NetDays => $days === null ? 'Pay within set days' : "Pay within {$days} days",
            SupplierTermsKind::EndOfMonth => 'End of month',
            null => null,
        };
    }

    public static function orderMethodLabel(SupplierOrderMethod $method): string
    {
        return match ($method) {
            SupplierOrderMethod::Email => 'Email',
            SupplierOrderMethod::Phone => 'Phone',
            SupplierOrderMethod::Portal => 'Supplier website',
            SupplierOrderMethod::Rep => 'Rep visit',
            SupplierOrderMethod::Edi => 'EDI',
        };
    }
}
