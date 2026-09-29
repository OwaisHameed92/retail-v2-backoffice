<?php

namespace App\Domain\Sync\Data;

use SensitiveParameter;

/**
 * One `POST sync/push` as it arrived (contract §3, §7, §17.8): the raw body and its `Content-Encoding`,
 * `X-SSPOS-Sync-Mode` / `X-SSPOS-Upload-Id`, the optional `Idempotency-Key`, the till's app version and sending
 * register (as sent) and the request's trace id. PushChanges checks and decodes it.
 */
final readonly class PushInput
{
    public function __construct(
        #[SensitiveParameter] public string $body,
        public ?string $encoding,
        public ?string $mode,
        public ?string $uploadId,
        public ?string $idempotencyKey,
        public string $appVersion,
        public string $tillRegisterId,
        public string $traceId,
    ) {}
}
