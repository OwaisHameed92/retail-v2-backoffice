<?php

namespace App\Domain\TillImages\Data;

use App\Domain\TillImages\Enums\ProductImageOrigin;

/**
 * One `PUT sync/product-images/{productId}` as read from the request (TillImageBody): the till's product id, the
 * picture's bytes, the `Content-Type` it declared and the `X-SSPOS-Image-Sha256` it declared (lower-case hex).
 * IMG-2: `origin` from `X-SSPOS-Image-Source` (ProductImageOrigin::fromHeader; `unknown` when missing or not known).
 */
final readonly class TillImageUpload
{
    public function __construct(
        public string $productId,
        public string $bytes,
        public string $contentType,
        public string $sha256,
        public ProductImageOrigin $origin = ProductImageOrigin::Unknown,
    ) {}
}
