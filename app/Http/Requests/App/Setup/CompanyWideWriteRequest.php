<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to a list every shop's tills share (suppliers, payment types, reasons, till staff and roles; module 4.5).
 * The route checks the ability; a one-shop user (module 3.3) may look but not change what every shop uses (403).
 * Used on its own for deletes; the save requests extend it with their rules.
 */
class CompanyWideWriteRequest extends FormRequest
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
        return [];
    }
}
