<?php

namespace App\Http\Requests\App\Setup;

/**
 * Setting a till staff member's PIN (typed twice) or giving them a fob (module 4.5). Route:
 * `company.can:staff.manage`. Neither value is flashed to the session; the Action checks the format.
 */
class StaffCredentialRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->routeIs('app.staff.pin')
            ? ['pin' => ['required', 'string', 'max:8', 'confirmed']]
            : ['rfid' => ['required', 'string', 'max:64']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Enter the new PIN.',
            'pin.max' => 'Enter a PIN of 4 to 8 digits.',
            'pin.confirmed' => 'The two PINs do not match.',
            'rfid.required' => 'Scan the fob or type its code.',
        ];
    }

    public function secret(): string
    {
        return (string) $this->input($this->routeIs('app.staff.pin') ? 'pin' : 'rfid');
    }
}
