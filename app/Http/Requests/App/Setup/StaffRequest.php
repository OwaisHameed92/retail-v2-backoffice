<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\Shared\Country\CountryModules;
use App\Domain\Shared\Country\LocalText;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a till staff member (module 4.5). Route: `company.can:staff.manage`. A new member needs a PIN
 * (typed twice); an edit leaves the PIN alone (StaffPinRequest changes it). `pin` is never flashed to the session.
 */
class StaffRequest extends CompanyWideWriteRequest
{
    /** Phase P10: where the country profile hides alcohol licensing, the form has no "Personal licence holder": keep it. */
    protected function prepareForValidation(): void
    {
        $id = $this->route('staff');

        $this->merge(CountryModules::keep(
            [CountryModules::ALCOHOL_LICENSING => ['is_personal_licence_holder']],
            fn () => is_string($id) ? TillUser::query()->find($id) : null,
            ['is_personal_licence_holder' => false],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('staff') === null;

        return [
            'name' => ['required', 'string', 'max:60'],
            'role_id' => ['required', 'string', 'max:64'],
            'is_active' => ['boolean'],
            'rate_per_hour' => ['nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'max_shift_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'is_service_staff' => ['boolean'],
            'allow_commission' => ['boolean'],
            'is_personal_licence_holder' => ['boolean'],
            'big_text_mode' => ['boolean'],
            'simple_mode_override' => ['required', Rule::in(['role', 'on', 'off'])],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['string', 'max:26'],
            'pin' => $creating ? ['required', 'string', 'confirmed'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter their name as it shows on the till.',
            'role_id.required' => 'Choose a till role.',
            'rate_per_hour.regex' => LocalText::currency('Enter an hourly rate in pounds, e.g. 11.44.'),
            'pin.required' => 'Give them a PIN to sign in to the till.',
            'pin.confirmed' => 'The two PINs do not match.',
        ];
    }

    /**
     * The Action's input.
     *
     * @return array{name: string, role_id: string, rate_per_hour: string|null, max_shift_hours: string|null,
     *     is_service_staff: bool, allow_commission: bool, is_personal_licence_holder: bool, simple_mode_override: bool|null,
     *     big_text_mode: bool, is_active: bool, branch_ids: list<string>, pin: string|null}
     */
    public function staff(): array
    {
        $override = $this->input('simple_mode_override');

        return [
            'name' => (string) $this->input('name'),
            'role_id' => (string) $this->input('role_id'),
            'rate_per_hour' => $this->filled('rate_per_hour') ? (string) $this->input('rate_per_hour') : null,
            'max_shift_hours' => $this->filled('max_shift_hours') ? (string) $this->input('max_shift_hours') : null,
            'is_service_staff' => $this->boolean('is_service_staff'),
            'allow_commission' => $this->boolean('allow_commission'),
            'is_personal_licence_holder' => $this->boolean('is_personal_licence_holder'),
            'simple_mode_override' => $override === 'role' ? null : $override === 'on',
            'big_text_mode' => $this->boolean('big_text_mode'),
            'is_active' => $this->boolean('is_active', true),
            'branch_ids' => array_values(array_map('strval', (array) $this->input('branch_ids', []))),
            'pin' => $this->filled('pin') ? (string) $this->input('pin') : null,
        ];
    }
}
