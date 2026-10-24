<?php

namespace App\Domain\TillImages\Data;

/**
 * One `PUT sync/product-images/{productId}` as read from the request (TillImageBody): the till's product id, the
 * picture's bytes, the `Content-Type` it declared and the `X-SSPOS-Image-Sha256` it declared (lower-case hex).
 */
final readonly class TillImageUpload
{
    public function __construct(
        public string $productId,
        public string $bytes,
        public string $contentType,
        public string $sha256,
    ) {}
}
