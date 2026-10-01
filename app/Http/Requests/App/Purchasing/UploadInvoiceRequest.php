<?php

namespace App\Http\Requests\App\Purchasing;

use App\Domain\Purchasing\Queries\InvoiceImportList;
use App\Domain\Tenancy\CurrentCompany;

/**
 * Uploading an invoice or delivery note (module 6.5): a PDF, JPG or PNG up to 10 MB (checked by content, not only the
 * name), for one shop. A one-shop user may only import for their own shop (403). The file is optional when the
 * invoice is entered by hand.
 */
class UploadInvoiceRequest extends InvoiceImportRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return parent::authorize() && ($restricted === null || $this->input('shopId') === $restricted);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shopId' => ['required', 'string', 'size:26'],
            'manual' => ['nullable', 'boolean'],
            'file' => [$this->boolean('manual') ? 'nullable' : 'required', 'file', 'max:'.InvoiceImportList::MAX_KB,
                'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the invoice to upload.',
            'file.max' => 'The file is larger than 10 MB. Upload a smaller scan or photo.',
            'file.mimes' => 'Upload a PDF, JPG or PNG.',
            'file.mimetypes' => 'Upload a PDF, JPG or PNG.',
        ];
    }
}
