<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded supplier invoice or delivery note (module 6.5), portal-only (never synced). `draft` is the corrected
 * invoice the user confirms (InvoiceDraft shape); `extracted` is what the model read, kept unchanged for comparison.
 * The file lives on the private `local` disk under `invoice-imports/{company}/`; it is deleted after
 * FILE_RETENTION_DAYS (PurgeInvoiceImportFiles) and the row after ROW_RETENTION_MONTHS (model:prune).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property int|null $user_id
 * @property InvoiceImportStatus $status
 * @property string $method ai|manual
 * @property string|null $file_name
 * @property string|null $file_path
 * @property string|null $file_mime
 * @property int|null $file_size
 * @property string|null $file_sha256
 * @property CarbonImmutable|null $file_purged_at
 * @property string|null $supplier_id
 * @property string|null $invoice_number
 * @property CarbonImmutable|null $invoice_date
 * @property string|null $gross_total
 * @property array<string, mixed>|null $extracted
 * @property array<string, mixed>|null $draft
 * @property string|null $error
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $purchase_order_id
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $extracted_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class InvoiceImport extends Model
{
    use BelongsToCompany, HasUlids, Prunable;

    public const DISK = 'local';

    public const FILE_RETENTION_DAYS = 90;

    public const ROW_RETENTION_MONTHS = 24;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceImportStatus::class,
            'file_size' => 'integer',
            'file_purged_at' => UtcDateTimeCast::class,
            'invoice_date' => 'immutable_date',
            'extracted' => 'array',
            'draft' => 'array',
            'result' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'extracted_at' => UtcDateTimeCast::class,
            'confirmed_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Copies the draft's supplier, number, date and total to their columns (lists, duplicate check). */
    public function syncHeader(): self
    {
        $draft = $this->draft ?? [];
        $this->supplier_id = $draft['supplierId'] ?? null;
        $this->invoice_number = $draft['invoiceNumber'] ?? null;
        $this->invoice_date = isset($draft['invoiceDate']) ? CarbonImmutable::parse($draft['invoiceDate'], 'UTC') : null;
        $this->gross_total = $draft['grossTotal'] ?? null;

        return $this;
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->file_purged_at === null;
    }

    public function deleteFile(): void
    {
        if ($this->file_path !== null) {
            Storage::disk(self::DISK)->delete($this->file_path);
        }

        $this->file_purged_at = CarbonImmutable::now('UTC');
    }

    /**
     * Rows older than the retention period, across every company (the pruner has no current company).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::withoutCompanyScope()->where('created_at', '<', CarbonImmutable::now('UTC')->subMonths(self::ROW_RETENTION_MONTHS));
    }

    protected function pruning(): void
    {
        if ($this->file_path !== null && $this->file_purged_at === null) {
            Storage::disk(self::DISK)->delete($this->file_path);
        }
    }
}
