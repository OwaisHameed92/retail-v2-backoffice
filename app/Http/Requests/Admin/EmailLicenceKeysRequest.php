<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Email this key to the owner" from the "Licence key created" dialog. Whoever could create the key (adding a
 * till: tenants.manage; issuing or replacing it: licences.manage) may send it. The keys come in the JSON body
 * only, never in the URL.
 */
class EmailLicenceKeysRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin
            && ($admin->hasAbility(AdminRole::LICENCES_MANAGE) || $admin->hasAbility(AdminRole::TENANTS_MANAGE));
    }

    /**
     * Validate the body only: a key in the query string is ignored (contract §17.11 rule 12).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->request->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'licences' => ['required', 'array', 'min:1', 'max:50'],
            'licences.*.id' => ['required', 'string', 'size:26'],
            'licences.*.key' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * licence id => plain key.
     *
     * @return array<string, string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ((array) $this->request->all('licences') as $row) {
            if (is_array($row) && isset($row['id'], $row['key'])) {
                $keys[(string) $row['id']] = (string) $row['key'];
            }
        }

        return $keys;
    }
}
