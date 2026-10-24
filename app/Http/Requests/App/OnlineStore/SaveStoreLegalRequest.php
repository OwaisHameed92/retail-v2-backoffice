<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The store's trader details and legal pages' extra text (OS-11, audit OSA-2): store-wide, so a one-shop user cannot
 * change them (403). The route checks `store.manage`. The values are checked by SaveStoreLegal.
 */
class SaveStoreLegalRequest extends FormRequest
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
