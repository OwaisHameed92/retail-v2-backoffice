<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Support\KeepCashTender;
use App\Domain\Setup\Support\PaymentTypeGroup;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a payment (tender) type, the buttons on the till's pay screen (the till's hub-owned `PaymentType`,
 * module 4.5). Saved through its model, so every till receives it at its next pull. Names are unique in the
 * business, except that the tills make some types once per shop (PaymentTypeGroup): editing one of those edits the
 * whole group, and only what was changed is written to each shop's row (a shop's own position or flags stay). The
 * till's own "Order deposit" / "Loyalty points" keep their name. A new type goes to the end of the list. The last
 * active cash type cannot be switched off (the till needs one to take cash and give change).
 */
final class SavePaymentType
{
    public const FLAGS = [
        'is_cash', 'is_card', 'is_voucher', 'is_points', 'is_account', 'is_drs_refund',
        'opens_drawer', 'show_on_payment', 'show_on_refund', 'show_on_customer_payment', 'is_active',
    ];

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  `name`, optional `position` and the FLAGS as booleans
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $typeId, array $data): PaymentType
    {
        return $this->tenancy->runAs($company, fn (): PaymentType => DB::transaction(function () use ($typeId, $data): PaymentType {
            $type = $typeId === null ? new PaymentType : PaymentType::query()->findOrFail($typeId);
            $group = $type->exists ? PaymentTypeGroup::members($type) : collect([$type]);
            $groupIds = $group->pluck('id')->filter()->values()->all();
            $name = trim((string) $data['name']);

            if (PaymentType::query()->whereKeyNot($groupIds)->whereRaw('LOWER(name) = ?', [PaymentTypeGroup::key($name)])->exists()) {
                throw ValidationException::withMessages(['name' => "There is already a payment type called {$name}."]);
            }

            if ($type->exists && PaymentTypeGroup::isTillSystem($type->name) && PaymentTypeGroup::key($name) !== PaymentTypeGroup::key($type->name)) {
                throw ValidationException::withMessages(['name' => "Each shop's till makes {$type->name} itself, so its name stays as it is."]);
            }

            $wanted = ['name' => $name];
            foreach (self::FLAGS as $flag) {
                $wanted[$flag] = (bool) ($data[$flag] ?? ($flag === 'is_active' || $flag === 'show_on_payment'));
            }
            $wanted['position'] = isset($data['position']) && $data['position'] !== '' ? (int) $data['position']
                : ($type->exists ? $type->position : ((int) PaymentType::query()->max('position')) + 1);

            if ($type->exists && $group->contains(fn (PaymentType $t) => $t->is_cash && $t->is_active) && ! ($wanted['is_cash'] && $wanted['is_active'])) {
                KeepCashTender::check($type, 'is_active', $groupIds);
            }

            // What the person changed on the line they edited (all of it for a new type) goes to every shop's row.
            $changes = $type->exists
                ? array_filter($wanted, fn ($value, string $field) => $type->getAttribute($field) !== $value, ARRAY_FILTER_USE_BOTH)
                : $wanted;

            foreach ($group as $row) {
                $this->apply($row, $changes);
            }

            return $type;
        }));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function apply(PaymentType $row, array $changes): void
    {
        $fields = ['name', 'position', ...self::FLAGS];
        $before = $row->exists ? $row->only($fields) : null;
        $row->forceFill($changes);

        if (! $row->exists || $row->isDirty()) {
            $row->save();
            $this->audit->handle($before === null ? 'payment_type.created' : 'payment_type.updated', $row, $before, $row->only($fields));
        }
    }
}
