<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * An expected API error with a stable machine code and an en-GB message for the shop owner.
 *
 *     throw new ApiException('licence.not_found', 'We could not find this licence key. Check it and try again.', 404);
 *
 * Rendered by {@see ApiExceptionRenderer} as `{code, message, traceId, retryAfterSeconds, rejectedKey}`, plus
 * `details` (an open object of machine-readable extras, contract §9/§17.12) when given.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $rejectedKey = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message, ?string $rejectedKey = null): self
    {
        return new self('request.invalid', $message, 400, null, $rejectedKey);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self($code, $message, 404);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, $message, 403);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }

    public static function rowRejected(string $rejectedKey, string $message): self
    {
        return new self('row.invalid', $message, 422, null, $rejectedKey);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self('rate_limited', ApiErrorMessages::RATE_LIMITED, 429, $retryAfterSeconds);
    }
}
