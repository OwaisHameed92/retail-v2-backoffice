<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\PaymentData;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Data\TenantActivity;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment list and detail (module 1.8). `tenants.view`. Recording is TenantBillingController::recordPayment.
 */
class PaymentController extends Controller
{
    use FindsBillingRecords;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'company' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $table = TableQuery::from($request)->sortable(['received_at', 'amount', 'company_name', 'sequence'])->defaultSort('received_at', 'desc');
        $search = $table->search();

        $query = Payment::withoutCompanyScope()
            ->select('payments.*')->addSelect('companies.name as company_name')
            ->join('companies', 'companies.id', '=', 'payments.company_id')
            ->when($filters['method'] ?? null, fn (Builder $q, string $method) => $q->where('payments.method', $method))
            ->when($filters['company'] ?? null, fn (Builder $q, string $company) => $q->where('payments.company_id', $company))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('payments.received_at', '>=', BillingDates::endOfDay(BillingDates::date($from)->subDay())))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('payments.received_at', '<=', BillingDates::endOfDay(BillingDates::date($to))))
            ->when($search !== null, function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('payments.number', 'like', $like)->orWhere('payments.reference', 'like', $like)->orWhere('companies.name', 'like', $like));
            });

        $company = isset($filters['company']) ? Company::query()->withTrashed()->find($filters['company']) : null;
        $amounts = $query->clone()->reorder()->toBase()->pluck('payments.amount');

        return Inertia::render('admin/billing/payments/index', [
            'payments' => $table->paginate($query, fn (Payment $payment) => PaymentData::row($payment)),
            'filters' => [
                'method' => $filters['method'] ?? null,
                'company' => $company === null ? null : ['id' => $company->id, 'name' => $company->name],
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'totals' => ['count' => $amounts->count(), 'amount' => BillingFormat::money(Money::sum($amounts))],
            'methods' => PaymentMethod::options(),
            'manualMethods' => PaymentMethod::options(manualOnly: true),
            'canManage' => $request->user('admin')?->hasAbility(AdminRole::BILLING_MANAGE) ?? false,
        ]);
    }

    public function show(string $payment): Response
    {
        $model = $this->findPayment($payment);
        $entries = AuditLog::query()->where('subject_type', $model->getMorphClass())->where('subject_id', $model->id)->latest('created_at')->get();
        $presenter = new TenantActivity($entries);

        return Inertia::render('admin/billing/payments/show', [
            'payment' => PaymentData::detail($model),
            'activity' => $entries->map(fn (AuditLog $entry) => $presenter->row($entry))->values(),
        ]);
    }
}
