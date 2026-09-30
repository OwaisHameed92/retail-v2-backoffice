<?php

namespace App\Http\Requests\App;

use App\Domain\Catalogue\Import\ImportColumns;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * Product CSV import (module 4.2): the upload (a CSV up to 20 MB) or the column mapping (field → column index).
 * Route: `company.can:catalogue.manage`.
 */
class ProductImportRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('app.products.imports.store')) {
            return ['file' => ['required', 'file', 'max:20480', 'extensions:csv,txt', 'mimes:csv,txt']];
        }

        return [
            'mapping' => ['present', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file.',
            'file.max' => 'The file must be 20 MB or smaller. Split it into smaller files.',
            'file.mimes' => 'Upload a CSV file (in Excel: File, Save as, CSV UTF-8).',
            'file.extensions' => 'Upload a CSV file (in Excel: File, Save as, CSV UTF-8).',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function mapping(): array
    {
        $mapping = [];

        foreach ((array) $this->validated('mapping', []) as $field => $index) {
            if ($index !== null && isset(ImportColumns::FIELDS[$field])) {
                $mapping[(string) $field] = (int) $index;
            }
        }

        return $mapping;
    }
}
