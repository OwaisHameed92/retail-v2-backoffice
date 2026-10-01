<?php

namespace App\Domain\Labels\Support;

/**
 * Label stocks the portal prints on (gap #6), in millimetres: common UK A4 sheets (Avery-compatible sizes, measured
 * from the sheet's top-left corner) and label-printer rolls (one label per page). `hPitch`/`vPitch` = distance from
 * one label's left/top edge to the next one's.
 */
final class LabelStocks
{
    public const DEFAULT = 'a4_3x8';

    /** @var array<string, array{name: string, kind: string, ref: string|null, pageWidth: float, pageHeight: float, cols: int, rows: int, width: float, height: float, top: float, left: float, hPitch: float, vPitch: float}> */
    private const STOCKS = [
        'a4_2x4' => ['name' => 'A4, 8 per sheet (2 × 4)', 'kind' => 'a4', 'ref' => 'L7165', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 2, 'rows' => 4, 'width' => 99.1, 'height' => 67.7, 'top' => 13.1, 'left' => 4.65, 'hPitch' => 101.6, 'vPitch' => 67.7],
        'a4_2x7' => ['name' => 'A4, 14 per sheet (2 × 7)', 'kind' => 'a4', 'ref' => 'L7163', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 2, 'rows' => 7, 'width' => 99.1, 'height' => 38.1, 'top' => 15.15, 'left' => 4.65, 'hPitch' => 101.6, 'vPitch' => 38.1],
        'a4_2x8' => ['name' => 'A4, 16 per sheet (2 × 8)', 'kind' => 'a4', 'ref' => 'L7162', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 2, 'rows' => 8, 'width' => 99.1, 'height' => 33.9, 'top' => 12.9, 'left' => 4.65, 'hPitch' => 101.6, 'vPitch' => 33.9],
        'a4_3x7' => ['name' => 'A4, 21 per sheet (3 × 7)', 'kind' => 'a4', 'ref' => 'L7160', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 3, 'rows' => 7, 'width' => 63.5, 'height' => 38.1, 'top' => 15.15, 'left' => 7.2, 'hPitch' => 66.0, 'vPitch' => 38.1],
        'a4_3x8' => ['name' => 'A4, 24 per sheet (3 × 8)', 'kind' => 'a4', 'ref' => 'L7159', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 3, 'rows' => 8, 'width' => 63.5, 'height' => 33.9, 'top' => 12.9, 'left' => 7.2, 'hPitch' => 66.0, 'vPitch' => 33.9],
        'a4_4x10' => ['name' => 'A4, 40 per sheet (4 × 10)', 'kind' => 'a4', 'ref' => 'L7654', 'pageWidth' => 210.0, 'pageHeight' => 297.0, 'cols' => 4, 'rows' => 10, 'width' => 45.7, 'height' => 25.4, 'top' => 21.5, 'left' => 9.7, 'hPitch' => 48.3, 'vPitch' => 25.4],
        'roll_50x30' => ['name' => 'Label printer roll, 50 × 30 mm', 'kind' => 'roll', 'ref' => null, 'pageWidth' => 50.0, 'pageHeight' => 30.0, 'cols' => 1, 'rows' => 1, 'width' => 50.0, 'height' => 30.0, 'top' => 0.0, 'left' => 0.0, 'hPitch' => 50.0, 'vPitch' => 30.0],
        'roll_58x40' => ['name' => 'Label printer roll, 58 × 40 mm', 'kind' => 'roll', 'ref' => null, 'pageWidth' => 58.0, 'pageHeight' => 40.0, 'cols' => 1, 'rows' => 1, 'width' => 58.0, 'height' => 40.0, 'top' => 0.0, 'left' => 0.0, 'hPitch' => 58.0, 'vPitch' => 40.0],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::STOCKS);
    }

    /**
     * @return array{key: string, name: string, kind: string, ref: string|null, pageWidth: float, pageHeight: float, cols: int, rows: int, width: float, height: float, top: float, left: float, hPitch: float, vPitch: float, perPage: int}
     */
    public static function get(string $key): array
    {
        $key = isset(self::STOCKS[$key]) ? $key : self::DEFAULT;
        $stock = self::STOCKS[$key];

        return ['key' => $key, ...$stock, 'perPage' => $stock['cols'] * $stock['rows']];
    }

    /**
     * Every stock, for the template form and the preview.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(fn (string $key) => self::get($key), self::keys());
    }

    /**
     * Where each label goes: pages of [x, y] positions (mm), after skipping `$skip` used labels on the first sheet.
     *
     * @return list<list<array{x: float, y: float, index: int}>>
     */
    public static function layout(string $key, int $count, int $skip = 0): array
    {
        $stock = self::get($key);
        $per = $stock['perPage'];
        $skip = $stock['kind'] === 'a4' ? max(0, min($skip, $per - 1)) : 0;
        $pages = [];

        for ($i = 0; $i < $count; $i++) {
            $slot = $i + $skip;
            $page = intdiv($slot, $per);
            $cell = $slot % $per;
            $pages[$page][] = [
                'x' => round($stock['left'] + ($cell % $stock['cols']) * $stock['hPitch'], 2),
                'y' => round($stock['top'] + intdiv($cell, $stock['cols']) * $stock['vPitch'], 2),
                'index' => $i,
            ];
        }

        return array_values($pages);
    }
}
