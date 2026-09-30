<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Import\CsvReader;
use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step 1 of a product CSV import: keeps the uploaded file (private `local` disk, per business), reads the header line
 * and guesses the column mapping. Nothing is written to the catalogue until the import is previewed and applied.
 */
final class StartProductImport
{
    public const DISK = 'local';

    public const MAX_COLUMNS = 100;

    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function handle(UploadedFile $file, ?User $user): ProductImport
    {
        $companyId = (string) $this->tenancy->require()->id;
        $path = $file->storeAs('product-imports/'.$companyId, Str::lower((string) Str::ulid()).'.csv', self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved. Please try again.']);
        }

        $headers = (new CsvReader(Storage::disk(self::DISK)->path($path)))->headers();

        if (array_filter($headers) === [] || count($headers) > self::MAX_COLUMNS) {
            Storage::disk(self::DISK)->delete($path);

            throw ValidationException::withMessages(['file' => 'The first line must name the columns (at most '.self::MAX_COLUMNS.').']);
        }

        return ProductImport::query()->create([
            'user_id' => $user?->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'status' => ImportStatus::Uploaded,
            'headers' => $headers,
            'mapping' => ImportColumns::guess($headers),
        ]);
    }
}
