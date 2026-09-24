<?php

namespace App\Domain\Shared\Exceptions;

/**
 * Default en-GB messages for generic API errors. Written for the shop owner who sees them on the till.
 */
final class ApiErrorMessages
{
    public const INVALID = 'The till sent a request we could not read.';

    public const UNAUTHENTICATED = 'The key sent by the till was not recognised. Check it and try again.';

    public const FORBIDDEN = 'This till is not allowed to do that.';

    public const NOT_FOUND = 'We could not find what the till asked for.';

    public const METHOD_NOT_ALLOWED = 'The till used a request type we do not support here.';

    public const TOO_LARGE = 'The till sent too much at once. It will try again with less.';

    public const RATE_LIMITED = 'Too many requests from this till. It will try again shortly.';

    public const BUSY = 'The service is busy. The till will try again shortly.';

    public const SERVER_ERROR = 'Something went wrong on our side. The till will try again shortly.';
}
