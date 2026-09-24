<?php

namespace App\Domain\Billing\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A simple credit note (CN-000001) that reduces what is owed on one issued invoice, split into net and VAT at
 * the invoice's rate. Issued only through IssueCreditNote.
 *
 * @property string $id
 * @property string $company_id
 * @property string $invoice_id
 * @property string $number
 * @property int $sequence
 * @property string $reason
 * @property string $net
 * @property string $vat
 * @property string $total
 * @property CarbonImmutable $issued_at
 * @property string|null $issued_by_admin_id
 * @property-read Invoice|null $invoice
 * @property-read Admin|null $issuedBy
 */
class CreditNote extends Model
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
            'sequence' => 'integer',
            'net' => MoneyCast::class,
            'vat' => MoneyCast::class,
            'total' => MoneyCast::class,
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Admin, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'issued_by_admin_id');
    }
}
