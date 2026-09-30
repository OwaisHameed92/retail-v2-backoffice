<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Support\KeepCashTender;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Removes a supplier, payment type or reason (module 4.5): a soft delete, so every till gets a `D` at its next pull
 * and past sales, orders and cash-ups keep pointing at it. The last active cash payment type cannot be removed.
 *
 *     app(DeleteSetupRow::class)->handle($company, Supplier::class, $supplierId);
 */
final class DeleteSetupRow
{
    /** @var array<class-string, string> */
    private const AUDIT = [Supplier::class => 'supplier', PaymentType::class => 'payment_type', Reason::class => 'reason'];

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  class-string<Supplier|PaymentType|Reason>  $class
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $class, string $id): void
    {
        if (! isset(self::AUDIT[$class])) {
            throw new InvalidArgumentException("{$class} is not a setup list.");
        }

        $this->tenancy->runAs($company, function () use ($class, $id): void {
            $row = $class::query()->findOrFail($id);

            if ($row instanceof PaymentType && $row->is_cash && $row->is_active) {
                KeepCashTender::check($row);
            }

            $row->delete();
            $this->audit->handle(self::AUDIT[$class].'.deleted', $row, ['name' => $row instanceof Reason ? $row->text : $row->name]);
        });
    }
}
