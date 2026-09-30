<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Support\KeepCashTender;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a payment (tender) type, the buttons on the till's pay screen (the till's hub-owned `PaymentType`,
 * module 4.5). Saved through its model, so every till receives it at its next pull. Names are unique in the
 * business; a new type goes to the end of the list. The last active cash type cannot be switched off (the till
 * needs one to take cash and give change).
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
        return $this->tenancy->runAs($company, function () use ($typeId, $data): PaymentType {
            $type = $typeId === null ? new PaymentType : PaymentType::query()->findOrFail($typeId);
            $name = trim((string) $data['name']);

            if (PaymentType::query()->when($type->exists, fn ($q) => $q->whereKeyNot($type->id))->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw ValidationException::withMessages(['name' => "There is already a payment type called {$name}."]);
            }

            $flags = [];
            foreach (self::FLAGS as $flag) {
                $flags[$flag] = (bool) ($data[$flag] ?? ($flag === 'is_active' || $flag === 'show_on_payment'));
            }

            if ($type->exists && $type->is_cash && $type->is_active && ! ($flags['is_cash'] && $flags['is_active'])) {
                KeepCashTender::check($type, 'is_active');
            }

            $before = $type->exists ? $type->only(['name', 'position', ...self::FLAGS]) : null;
            $position = isset($data['position']) && $data['position'] !== '' ? (int) $data['position']
                : ($type->exists ? $type->position : ((int) PaymentType::query()->max('position')) + 1);

            $type->forceFill(['name' => $name, 'position' => $position, ...$flags]);

            if (! $type->exists || $type->isDirty()) {
                $type->save();
                $this->audit->handle($before === null ? 'payment_type.created' : 'payment_type.updated', $type, $before, $type->only(['name', 'position', ...self::FLAGS]));
            }

            return $type;
        });
    }
}
