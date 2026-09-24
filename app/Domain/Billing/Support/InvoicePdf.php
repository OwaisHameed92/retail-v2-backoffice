<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Contracts\RendersAttachment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The branded A4 invoice PDF (dompdf, resources/views/billing/invoice-pdf.blade.php). Built from the same
 * InvoiceDocument as the admin preview. Remote resources are off: the logo is embedded as a data URI.
 */
final class InvoicePdf implements RendersAttachment
{
    /** Data URI of the print logo ('' when unavailable). */
    private static ?string $logo = null;

    public function render(Invoice $invoice): string
    {
        $pdf = Pdf::loadView('billing.invoice-pdf', [
            'doc' => InvoiceDocument::for($invoice),
            'logo' => self::logo(),
        ])->setPaper('a4')->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'dpi' => 144,
        ]);

        return $pdf->output();
    }

    /** "INV-000123.pdf", or "draft-<id>.pdf" before a draft has a number. */
    public static function filename(Invoice $invoice): string
    {
        return ($invoice->number ?? 'draft-'.strtolower($invoice->id)).'.pdf';
    }

    /** For InvoiceMail: the invoice id → PDF bytes, rendered in the queue worker. */
    public function renderAttachment(string $key): ?string
    {
        $invoice = Invoice::withoutCompanyScope()->find($key);

        return $invoice === null ? null : $this->render($invoice);
    }

    /** The logo, scaled to print size and flattened on white (a large alpha PNG makes dompdf slow). Cached per process. */
    private static function logo(): ?string
    {
        if (self::$logo !== null) {
            return self::$logo ?: null;
        }

        $path = public_path('images/brand/switch-save-logo.png');
        $source = is_readable($path) && function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false;

        if ($source === false) {
            self::$logo = '';

            return null;
        }

        $width = 480;
        $height = max(1, (int) round(imagesy($source) * $width / max(1, imagesx($source))));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        imagepng($canvas, null, 9);
        $bytes = (string) ob_get_clean();

        return self::$logo = 'data:image/png;base64,'.base64_encode($bytes);
    }
}
