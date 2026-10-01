<?php

namespace App\Domain\Audit\Support;

use Illuminate\Support\Str;

/**
 * Readable labels for audit actions ("licence.suspended" → "Licence suspended"), record types
 * ("App\Domain\Licensing\Models\Licence" → "Licence") and fields ("max_branches" → "Max branches").
 */
final class AuditLabels
{
    public static function action(string $action): string
    {
        return str_replace('Two factor', 'Two-factor', self::sentence(str_replace(['.', '_', '-'], ' ', $action)));
    }

    public static function group(string $action): string
    {
        return Str::before($action, '.');
    }

    public static function type(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'Record';
        }

        return self::sentence(Str::headline(class_basename($type)));
    }

    public static function field(string $field): string
    {
        return self::sentence(Str::headline($field));
    }

    private static function sentence(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return Str::ucfirst(Str::lower($text));
    }
}
