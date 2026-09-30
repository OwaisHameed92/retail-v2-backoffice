<?php

namespace App\Http\Requests\App\Pharmacy;

use App\Domain\Pharmacy\Actions\SaveMedicineClass;
use App\Domain\TillData\Enums\MedicineClassificationClass;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Validation\Rule;

/**
 * A product's medicine class (module 5.10). Every shop's tills share it, so a one-shop user may look but not change it
 * (CompanyWideWriteRequest). The routes check `pharmacy.view` and `catalogue.manage`.
 */
class MedicineClassRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class' => ['required', Rule::enum(MedicineClassificationClass::class)],
            'note' => ['nullable', 'string', 'max:'.SaveMedicineClass::NOTE_MAX],
        ];
    }

    public function medicineClass(): MedicineClassificationClass
    {
        return MedicineClassificationClass::from((string) $this->validated('class'));
    }
}
