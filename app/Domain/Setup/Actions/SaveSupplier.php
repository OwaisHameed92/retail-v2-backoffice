<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SupplierOrderMethod;
use App\Domain\TillData\Enums\SupplierTermsKind;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a supplier (the till's hub-owned `Supplier`, module 4.5). Saved through its model, so every till
 * receives it at its next pull (HubOwnedRow). The code is unique in the business; left blank, one is made from the
 * name ("Aire Valley Cash & Carry" → "AIREVA", then "AIREVA2"…). Delivery days are stored as the till writes them
 * ("tuesday, friday").
 *
 *     app(SaveSupplier::class)->handle($company, null, ['name' => 'Aire Valley Cash & Carry', ...]);
 */
final class SaveSupplier
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  snake_case columns from SupplierRequest; `delivery_days` a list of days
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $supplierId, array $data): Supplier
    {
        return $this->tenancy->runAs($company, function () use ($supplierId, $data): Supplier {
            $supplier = $supplierId === null ? new Supplier : Supplier::query()->findOrFail($supplierId);
            $name = trim((string) $data['name']);
            $code = strtoupper(trim((string) ($data['code'] ?? '')));

            if ($code === '') {
                $code = $supplier->exists && $supplier->code !== '' ? $supplier->code : $this->codeFor($name);
            } elseif (Supplier::query()->when($supplier->exists, fn ($q) => $q->whereKeyNot($supplier->id))->whereRaw('UPPER(code) = ?', [$code])->exists()) {
                throw ValidationException::withMessages(['code' => "Another supplier already has the code {$code}."]);
            }

            $days = array_values(array_intersect(self::DAYS, array_map('strval', (array) ($data['delivery_days'] ?? []))));
            $before = $supplier->exists ? $this->summary($supplier) : null;

            $supplier->forceFill([
                'name' => $name,
                'code' => $code,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'contact_name' => self::text($data['contact_name'] ?? null),
                'phone' => self::text($data['phone'] ?? null),
                'email' => self::text($data['email'] ?? null),
                'address_line1' => self::text($data['address_line1'] ?? null),
                'address_line2' => self::text($data['address_line2'] ?? null),
                'town' => self::text($data['town'] ?? null),
                'postcode' => ($postcode = self::text($data['postcode'] ?? null)) === null ? null : strtoupper($postcode),
                'account_number' => self::text($data['account_number'] ?? null),
                'terms_kind' => SupplierTermsKind::from((string) ($data['terms_kind'] ?? SupplierTermsKind::OnDelivery->value)),
                'payment_terms_days' => (int) ($data['payment_terms_days'] ?? 0),
                'default_lead_days' => (int) ($data['default_lead_days'] ?? 0),
                'minimum_order_value' => Money::normalise(($data['minimum_order_value'] ?? null) ?: '0'),
                'order_method' => SupplierOrderMethod::from((string) ($data['order_method'] ?? SupplierOrderMethod::Phone->value)),
                'delivery_days' => implode(', ', $days),
                'vat_number' => ($vat = self::text($data['vat_number'] ?? null)) === null ? null : strtoupper($vat),
                'notes' => self::text($data['notes'] ?? null),
            ]);

            if (! $supplier->exists || $supplier->isDirty()) {
                $supplier->save();
                $this->audit->handle($before === null ? 'supplier.created' : 'supplier.updated', $supplier, $before, $this->summary($supplier));
            }

            return $supplier;
        });
    }

    private function codeFor(string $name): string
    {
        $base = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($name))), 0, 6) ?: 'SUPP';
        $taken = array_map('strtoupper', Supplier::withTrashed()->where('code', 'like', $base.'%')->pluck('code')->all());
        $code = $base;

        for ($n = 2; in_array($code, $taken, true); $n++) {
            $code = $base.$n;
        }

        return $code;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function summary(Supplier $supplier): array
    {
        return ['name' => $supplier->name, 'code' => $supplier->code, 'is_active' => $supplier->is_active, 'terms_kind' => $supplier->terms_kind?->value];
    }
}
