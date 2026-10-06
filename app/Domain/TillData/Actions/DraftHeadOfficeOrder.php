<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Data\HeadOfficeOrderData;
use App\Domain\TillData\Data\HeadOfficeOrderLine;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Sync\HubVersions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafts (or changes) a head-office purchase order for one shop (contract v1.4 §10.6, samples/pull-reply.head-office
 * .json). `PurchaseOrder` stays branch-owned: the portal writes the order and its lines once for that shop (with the
 * query builder: the model is read-only), and only that shop's pull carries them (`hubDrafted`). `origin` is
 * `headOffice`, `number` is our own per-shop head-office number (HO-000123, reference HO-LDS-000123), `receivedQty`
 * 0, the sender/canceller user ids null (our users are not till users), `rowVersion` 0 (the shop's first push of it
 * always wins).
 *
 * Changes are allowed until the shop owns the order: once its till has pushed the order (sent, cancelled, or goods
 * booked in) every change is refused ("draft a new order instead"), as is re-opening a cancelled order. A changed
 * order is sent whole again; lines left out are removed (pulled as `D`). The purchasing screens (5.2) call this.
 *
 *     $order = app(DraftHeadOfficeOrder::class)->handle($leeds, HeadOfficeOrderData::fromArray($input));
 */
final class DraftHeadOfficeOrder
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly HubVersions $versions,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, HeadOfficeOrderData $data, ?string $orderId = null): PurchaseOrder
    {
        $company = $branch->company()->firstOrFail();

        [$id, $lineIds, $before] = $this->tenancy->runAs($company, fn (): array => DB::transaction(fn (): array => $this->write($branch, $data, $orderId)));

        // Parents first: the order, then its lines, straight after it (§8 "send parents first").
        $this->versions->stamp($branch->company_id, 'PurchaseOrder', [$id]);
        $this->versions->stamp($branch->company_id, 'PurchaseOrderLine', $lineIds);

        $order = PurchaseOrder::withoutCompanyScope()->findOrFail($id);
        $this->audit->handle($before === null ? 'purchase_order.head_office_drafted' : 'purchase_order.head_office_changed', $order, $before, [
            'reference' => $order->reference, 'status' => $order->status?->value, 'gross_total' => $order->gross_total, 'lines' => count($data->lines),
        ], companyId: $branch->company_id);

        return $order;
    }

    /**
     * Writes the order and its lines in the caller's transaction.
     *
     * @return array{0: string, 1: list<string>, 2: array<string, mixed>|null} order id, line ids, the order before
     *
     * @throws ValidationException
     */
    private function write(Branch $branch, HeadOfficeOrderData $data, ?string $orderId): array
    {
        $this->validate($branch->company_id, $data);
        $current = $orderId === null ? null : $this->editable($branch, $orderId);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $id = $current->id ?? Ulid::new();
        $number = $current !== null ? (int) $current->number : $this->nextNumber($branch);
        $totals = $data->totals();
        $status = $data->status->value;

        DB::table('purchase_orders')->upsert([[
            'id' => $id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id,
            'supplier_id' => $data->supplierId, 'number' => $number, 'status' => $status,
            'expected_date' => $data->expectedDate,
            'sent_at' => $status === 'sent' ? ($current->sent_at ?? $now) : null, 'sent_by_user_id' => null,
            'cancelled_at' => $status === 'cancelled' ? $now : null, 'cancelled_by_user_id' => null,
            'cancel_reason' => $status === 'cancelled' ? $data->cancelReason : null, 'notes' => $data->notes,
            'net_total' => $totals['net'], 'vat_total' => $totals['vat'], 'gross_total' => $totals['gross'],
            'discount_amount' => '0.00', 'discount_reason_id' => null, 'origin' => 'headOffice',
            'order_no' => sprintf('HO-%06d', $number), 'branch_code' => $branch->code,
            'reference' => sprintf('HO-%s-%06d', $branch->code, $number), 'row_version' => 0,
            'created_at' => $current->created_at ?? $now, 'updated_at' => $now, 'deleted_at' => null,
            'hub_version' => null, 'origin_branch_id' => null, 'hub_drafted_at' => $now,
        ]], ['id']);

        return [$id, $this->writeLines($branch, $id, $data, $now), $current === null ? null : ['status' => $current->status, 'gross_total' => $current->gross_total]];
    }

    /**
     * @return list<string> line ids written or removed
     */
    private function writeLines(Branch $branch, string $orderId, HeadOfficeOrderData $data, string $now): array
    {
        $existing = DB::table('purchase_order_lines')->where('company_id', $branch->company_id)->where('purchase_order_id', $orderId)
            ->whereNull('deleted_at')->orderBy('position')->get(['id', 'created_at'])->all();
        $rows = [];
        $ids = [];

        foreach ($data->lines as $i => $line) {
            $kept = $existing[$i] ?? null;
            $ids[] = $lineId = $kept->id ?? Ulid::new();
            $rows[] = [
                'id' => $lineId, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'purchase_order_id' => $orderId,
                'product_id' => $line->productId, 'position' => $i + 1, 'ordered_cases' => $line->orderedCases,
                'case_qty_snapshot' => $line->caseQty, 'ordered_units' => Money::normalise($line->orderedUnits(), 4),
                'unit_cost_snapshot' => $line->unitCost, 'received_qty' => '0.0000', 'vat_rate_id' => $line->vatRateId,
                'vat_percentage' => $line->vatPercentage, 'row_version' => 0, 'created_at' => $kept->created_at ?? $now,
                'updated_at' => $now, 'deleted_at' => null, 'hub_version' => null, 'origin_branch_id' => null, 'hub_drafted_at' => $now,
            ];
        }

        DB::table('purchase_order_lines')->upsert($rows, ['id']);

        $removed = array_map(fn (object $row) => (string) $row->id, array_slice($existing, count($data->lines)));

        if ($removed !== []) {
            DB::table('purchase_order_lines')->whereIn('id', $removed)
                ->update(['deleted_at' => $now, 'updated_at' => $now, 'hub_version' => null, 'hub_drafted_at' => $now]);
        }

        return [...$ids, ...$removed];
    }

    /**
     * The order being changed, if the portal may still change it.
     *
     * @throws ValidationException
     */
    private function editable(Branch $branch, string $orderId): object
    {
        $order = DB::table('purchase_orders')->where('company_id', $branch->company_id)->where('id', $orderId)->lockForUpdate()->first();

        if ($order === null || $order->origin !== 'headOffice' || $order->hub_drafted_at === null || $order->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['order' => 'This is not a head-office order drafted for this shop.']);
        }

        if ($order->origin_branch_id !== null) {
            throw ValidationException::withMessages(['order' => 'The shop has already sent, cancelled or started receiving this order. Draft a new order instead.']);
        }

        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'A cancelled order cannot be re-opened. Draft a new order instead.']);
        }

        return $order;
    }

    private function nextNumber(Branch $branch): int
    {
        return 1 + (int) DB::table('purchase_orders')->where('company_id', $branch->company_id)->where('branch_id', $branch->id)
            ->where('origin', 'headOffice')->max('number');
    }

    /**
     * @throws ValidationException
     */
    private function validate(string $companyId, HeadOfficeOrderData $data): void
    {
        $exists = fn (string $table, array $ids) => DB::table($table)->where('company_id', $companyId)->whereIn('id', $ids)->whereNull('deleted_at')->count() === count(array_unique($ids));
        $errors = [];

        if (! in_array($data->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Cancelled], true)) {
            $errors['status'] = 'A head-office order is a draft, sent or cancelled.';
        }

        if ($data->supplierId === '' || ! $exists('suppliers', [$data->supplierId])) {
            $errors['supplierId'] = 'Choose one of this business\'s suppliers.';
        }

        if ($data->expectedDate !== null && CarbonImmutable::hasFormat($data->expectedDate, 'Y-m-d') === false) {
            $errors['expectedDate'] = 'Enter the expected date as YYYY-MM-DD.';
        }

        if ($data->lines === []) {
            $errors['lines'] = 'Add at least one product.';
        } elseif (! $exists('products', array_map(fn (HeadOfficeOrderLine $l) => $l->productId, $data->lines))) {
            $errors['lines'] = 'Every line must be one of this business\'s products.';
        } elseif (! $exists('vat_rates', array_map(fn (HeadOfficeOrderLine $l) => $l->vatRateId, $data->lines))) {
            $errors['lines'] = 'Every line needs one of this business\'s VAT rates.';
        }

        foreach ($data->lines as $i => $line) {
            if ($line->caseQty < 1 || $line->orderedCases < 0 || $line->looseUnits < 0 || $line->orderedUnits() < 1
                || Money::isNegative($line->unitCost) || Money::compare($line->vatPercentage, '100') > 0 || Money::isNegative($line->vatPercentage)) {
                $errors["lines.{$i}"] = 'Line '.($i + 1).': order at least one unit, at a cost of '.MoneyFormat::whole('0').' or more and a VAT rate of 0–100%.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
