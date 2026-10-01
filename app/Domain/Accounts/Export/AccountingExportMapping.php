<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's own mapping for one package (gap #8): our account code → their account code (`kind` account), or our
 * VAT code → their sales / purchases tax codes (`kind` vat; `our_code` "-" = lines with no VAT rate).
 *
 * @property string $id
 * @property string $company_id
 * @property string $target
 * @property string $kind
 * @property string $our_code
 * @property string $their_code
 * @property string|null $their_purchase_code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AccountingExportMapping extends Model
{
    use BelongsToCompany, HasUlids;

    public const KIND_ACCOUNT = 'account';

    public const KIND_VAT = 'vat';

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTimeCast::class, 'updated_at' => UtcDateTimeCast::class];
    }
}
