<?php

namespace App\Http\Requests\App\Accounts;

use App\Domain\Accounts\Export\ExportTarget;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saves the account and VAT code mapping of one package (gap #8). Codes are short text (their chart may use names,
 * as QuickBooks does). A one-shop user may not change the business's mapping. The route checks `accounts.export`.
 */
class ExportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CurrentCompany::class)->restrictedBranchId() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', Rule::enum(ExportTarget::class)],
            'accounts' => ['array', 'max:500'],
            'accounts.*' => ['nullable', 'string', 'max:100'],
            'vat' => ['array', 'max:50'],
            'vat.*.sales' => ['nullable', 'string', 'max:100'],
            'vat.*.purchases' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function target(): ExportTarget
    {
        return ExportTarget::from((string) $this->validated('target'));
    }

    /** @return array<string, string|null> */
    public function accounts(): array
    {
        $out = [];

        foreach ((array) $this->validated('accounts', []) as $code => $their) {
            if (preg_match('/^[0-9A-Za-z.\-—]{1,20}$/u', (string) $code) === 1) {
                $out[(string) $code] = $their === null ? null : (string) $their;
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    public function vat(): array
    {
        $out = [];

        foreach ((array) $this->validated('vat', []) as $code => $pair) {
            if (preg_match('/^[0-9A-Za-z\-]{1,10}$/', (string) $code) === 1 && is_array($pair)) {
                $out[(string) $code] = [isset($pair['sales']) ? (string) $pair['sales'] : null, isset($pair['purchases']) ? (string) $pair['purchases'] : null];
            }
        }

        return $out;
    }
}
