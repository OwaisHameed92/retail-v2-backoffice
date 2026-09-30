<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Support\KeepCashTender;
use App\Domain\Setup\Support\PaymentTypeGroup;
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
 * and past sales, orders and cash-ups keep pointing at it. The last active cash payment type cannot be removed; a
 * payment type goes with its same-named rows of every shop, and the till's own ones are never removed.
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
            $rows = $row instanceof PaymentType ? $this->paymentTypeGroup($row) : [$row];

            foreach ($rows as $each) {
                $each->delete();
                $this->audit->handle(self::AUDIT[$class].'.deleted', $each, ['name' => $each instanceof Reason ? $each->text : $each->name]);
            }
        });
    }

    /**
     * A payment type goes with its same-named rows of the other shops (PaymentTypeGroup); the till's own types stay.
     *
     * @return list<PaymentType>
     *
     * @throws ValidationException
     */
    private function paymentTypeGroup(PaymentType $type): array
    {
        if (PaymentTypeGroup::isTillSystem($type->name)) {
            throw ValidationException::withMessages(['status' => "Each shop's till needs {$type->name}, so it cannot be removed. Untick \"Show when taking payment\" to hide it instead."]);
        }

        $group = PaymentTypeGroup::members($type);

        if ($group->contains(fn (PaymentType $t) => $t->is_cash && $t->is_active)) {
            KeepCashTender::check($type, 'status', $group->pluck('id')->all());
        }

        return $group->values()->all();
    }
}
