<?php

namespace App\Domain\Shared\Support;

use Illuminate\Http\Request;

/**
 * One ULID per request, shared by the `X-Trace-Id` header, the error body and the log context.
 */
final class TraceId
{
    public const ATTRIBUTE = 'traceId';

    public const HEADER = 'X-Trace-Id';

    public static function for(Request $request): string
    {
        $traceId = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($traceId) || $traceId === '') {
            $traceId = Ulid::new();
            $request->attributes->set(self::ATTRIBUTE, $traceId);
        }

        return $traceId;
    }
}
