<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Ai\Actions\CallModel;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceDraft;
use App\Domain\Purchasing\Invoices\InvoiceExtractionPrompt;
use App\Domain\Purchasing\Invoices\InvoiceMatcher;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Has the model read an uploaded invoice (module 6.5): one metered call (CallModel: AI switch, key, plan feature
 * `assist_invoice_scan`, monthly allowance, an `ai_usage` row) with the document and the strict `record_invoice` tool.
 * Its tool input is cleaned (InvoiceDraft), matched to the supplier, products, order and delivery (InvoiceMatcher)
 * and saved for review; nothing else changes. When reading fails, the import says why and can be retried or entered
 * by hand. Runs in the company's scope (ReadInvoiceJob).
 */
final class ReadInvoice
{
    public function __construct(
        private readonly CallModel $model,
        private readonly InvoiceMatcher $matcher,
    ) {}

    public function handle(InvoiceImport $import, User $user, Company $company): InvoiceImport
    {
        if ($import->status !== InvoiceImportStatus::Reading) {
            return $import;
        }

        if (! $import->hasFile() || ! Storage::disk(InvoiceImport::DISK)->exists((string) $import->file_path)) {
            return $this->fail($import, 'The uploaded file is no longer here. Upload it again or enter the invoice by hand.');
        }

        try {
            $context = AiContext::forUser($user, $company, AiFeature::InvoiceImport);
            $data = base64_encode((string) Storage::disk(InvoiceImport::DISK)->get((string) $import->file_path));
            $response = $this->model->handle($context, InvoiceExtractionPrompt::request($data, (string) $import->file_mime));
        } catch (AiUnavailable $e) {
            return $this->fail($import, $e->getMessage());
        } catch (AiAccessDenied) {
            return $this->fail($import, 'You are no longer a member of this business.');
        }

        $import->fill([
            'model' => mb_substr($response->model, 0, 80),
            'input_tokens' => $response->usage->inputTokens + $response->usage->cacheReadTokens + $response->usage->cacheWriteTokens,
            'output_tokens' => $response->usage->outputTokens,
        ]);

        $call = collect($response->toolUses())->firstWhere('name', InvoiceExtractionPrompt::TOOL);

        if ($response->isRefusal() || $call === null) {
            Log::info('Invoice import: no record_invoice call.', ['import' => $import->id, 'stop' => $response->stopReason]);

            return $this->fail($import, 'We could not read this document. Try a clearer photo or the PDF, or enter the invoice by hand.');
        }

        if (($call['input']['documentType'] ?? null) === 'other') {
            return $this->fail($import, 'This does not look like a supplier invoice or delivery note. Upload the invoice, or enter it by hand.');
        }

        $draft = $this->matcher->apply(InvoiceDraft::fromExtraction($call['input']), $import->branch_id);

        $import->fill([
            'status' => InvoiceImportStatus::Review,
            'extracted' => $draft,
            'draft' => $draft,
            'error' => null,
            'extracted_at' => CarbonImmutable::now('UTC'),
        ])->syncHeader()->save();

        return $import;
    }

    private function fail(InvoiceImport $import, string $message): InvoiceImport
    {
        $import->fill(['status' => InvoiceImportStatus::Failed, 'error' => mb_substr($message, 0, 300)])->save();

        return $import;
    }
}
