<?php

namespace App\Domain\Labels\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;

/**
 * Shelf labels as a PDF (gap #6, dompdf, resources/views/labels/sheet-pdf.blade.php): one page per A4 sheet, or one per
 * roll label, each label placed in millimetres from LabelStocks so it lands on the stock's die-cut labels. Copies are
 * expanded here; `$skip` leaves the first labels of a part-used sheet empty.
 */
final class LabelPdf
{
    public const MAX_LABELS = 2000;

    /**
     * @param  list<array<string, mixed>>  $labels  LabelContent::for()
     * @param  array<string, bool>  $options  LabelTemplate options
     */
    public function render(array $labels, string $stock, array $options, int $skip = 0): string
    {
        $expanded = [];
        foreach ($labels as $label) {
            for ($i = 0; $i < (int) $label['copies']; $i++) {
                $expanded[] = $label;
            }
        }

        if (count($expanded) > self::MAX_LABELS) {
            throw ValidationException::withMessages(['ids' => 'Print at most '.self::MAX_LABELS.' labels at a time.']);
        }

        $sheet = LabelStocks::get($stock);
        $pages = LabelStocks::layout($sheet['key'], count($expanded), $skip);
        $scale = max(0.75, min(1.8, $sheet['height'] / 33.9));

        return Pdf::loadView('labels.sheet-pdf', [
            'stock' => $sheet, 'pages' => $pages, 'labels' => $expanded, 'options' => $options, 'scale' => $scale,
        ])->setPaper([0, 0, $sheet['pageWidth'] * 72 / 25.4, $sheet['pageHeight'] * 72 / 25.4])->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'dpi' => 300,
        ])->output();
    }
}
