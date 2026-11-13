<?php

namespace App\Domain\Workshop\Support;

use RuntimeException;

/**
 * An error answer of the BikeBook connector in BikeBook's shape (WORKSHOP-BIKEBOOK.md §2): `{error, message}` with
 * `error` = `invalid_request` (400, BikeBook skips the item), `unauthorized` (401) / `forbidden` (403, BikeBook marks
 * the connection failed), `not_found`, `order_locked` / `direct_mode` (409), `rate_limited` (429 + Retry-After). The OAuth token
 * endpoint uses RFC 6749 codes (`invalid_grant`, `invalid_client`…) and adds `error_description`.
 */
final class ConnectorException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function unauthorized(string $message = 'Send a valid access token (Authorization: Bearer) or API key (ApiKey header).'): self
    {
        return new self(401, 'unauthorized', $message, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function locked(): self
    {
        return new self(409, 'order_locked', 'The shop has opened this order at the till. Change it at the till.');
    }

    /** A new order for a shop whose till takes BikeBook jobs itself (`workshop.job_source` = direct): the till would skip it. */
    public static function directMode(): self
    {
        return new self(409, 'direct_mode', "This shop's till takes jobs straight from BikeBook (Direct mode), not through Send to POS. The shop can switch its till's job source to the connector.");
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests. Wait and try again.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    public static function oauth(int $status, string $error, string $message): self
    {
        return new self($status, $error, $message);
    }
}
