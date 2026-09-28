<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Casts\CalendarDateCast;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Shared\Casts\QuantityCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice, usually one till's licence for the period: net = quantity × unit price, VAT on the
 * net at the invoice rate, gross = net + VAT (each rounded half away from zero to the penny).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $company_id
 * @property string|null $licence_id
 * @property string|null $register_id
 * @property string|null $branch_id Per-branch pricing (module 1.13): the branch this line pays for.
 * @property string|null $plan_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $net
 * @property string $vat
 * @property string $gross
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property-read Invoice|null $invoice
 * @property-read Licence|null $licence
 */
class InvoiceLine extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => QuantityCast::class,
            'unit_price' => MoneyCast::class,
            'net' => MoneyCast::class,
            'vat' => MoneyCast::class,
            'gross' => MoneyCast::class,
            'period_start' => CalendarDateCast::class,
            'period_end' => CalendarDateCast::class,
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * The licence this line pays for (read across companies like the invoice itself; revoked and deleted too).
     *
     * @return BelongsTo<Licence, $this>
     */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class)->withoutGlobalScope(CompanyScope::class)->withTrashed();
    }
}
