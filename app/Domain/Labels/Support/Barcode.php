<?php

namespace App\Domain\Labels\Support;

/**
 * Barcodes for shelf labels as SVG (gap #6): EAN-13 for a valid 13-digit code (or a 12-digit UPC-A, printed as EAN-13
 * with a leading 0), Code 128 for anything else printable (set C for an even run of digits, else set B). Bars are
 * drawn as rects one unit per module, with a 10-module quiet zone each side; the SVG stretches to the box it is given.
 */
final class Barcode
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** Code 128 bar/space widths for values 0–106 (103–105 start A/B/C, 106 stop). */
    public const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232',
        '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122',
        '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311',
        '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113',
        '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422',
        '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111',
        '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121',
        '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const QUIET = 10;

    /**
     * The barcode for a code, or null when it cannot be printed.
     *
     * @return array{type: string, text: string, modules: string, svg: string}|null
     */
    public static function for(?string $code): ?array
    {
        $code = trim((string) $code);

        if ($code === '' || strlen($code) > 48 || preg_match('/^[\x20-\x7E]+$/', $code) !== 1) {
            return null;
        }

        if (preg_match('/^\d{12}$/', $code) === 1 && self::checkDigit('0'.substr($code, 0, 11)) === (int) $code[11]) {
            $code = '0'.$code;
        }

        $ean = preg_match('/^\d{13}$/', $code) === 1 && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
        $modules = $ean ? self::ean13($code) : self::code128($code);

        return ['type' => $ean ? 'ean13' : 'code128', 'text' => $code, 'modules' => $modules, 'svg' => self::svg($modules)];
    }

    /** EAN-13 check digit of the first 12 digits. */
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** 95 modules: guard, six left digits (parity from the first digit), centre guard, six right digits, guard. */
    public static function ean13(string $code): string
    {
        $parity = self::PARITY[(int) $code[0]];
        $bits = '101';

        for ($i = 1; $i <= 6; $i++) {
            $bits .= ($parity[$i - 1] === 'L' ? self::L : self::G)[(int) $code[$i]];
        }

        $bits .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::R[(int) $code[$i]];
        }

        return $bits.'101';
    }

    /** Start, data, modulo-103 check, stop, as module bits. */
    public static function code128(string $code): string
    {
        $digits = preg_match('/^(\d\d)+$/', $code) === 1 && strlen($code) >= 4;
        $values = [$digits ? 105 : 104];

        foreach ($digits ? str_split($code, 2) : str_split($code) as $part) {
            $values[] = $digits ? (int) $part : ord($part) - 32;
        }

        $check = $values[0];
        foreach (array_slice($values, 1) as $i => $value) {
            $check += $value * ($i + 1);
        }
        $values[] = $check % 103;
        $values[] = 106;

        $bits = '';
        foreach ($values as $value) {
            foreach (str_split(self::CODE128[$value]) as $i => $width) {
                $bits .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return $bits;
    }

    /** Bars as rects (adjacent dark modules merged), stretched to fill the box (`preserveAspectRatio="none"`). */
    public static function svg(string $modules): string
    {
        $width = strlen($modules) + 2 * self::QUIET;
        $rects = '';

        preg_match_all('/1+/', $modules, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as [$run, $offset]) {
            $rects .= '<rect x="'.($offset + self::QUIET).'" y="0" width="'.strlen($run).'" height="40"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 40" width="'.$width.'" height="40" preserveAspectRatio="none" shape-rendering="crispEdges">'
            .'<rect x="0" y="0" width="'.$width.'" height="40" fill="#ffffff"/><g fill="#000000">'.$rects.'</g></svg>';
    }
}
