<?php

namespace App\Domain\TillImages\Data;

/** The web copy ImageReencoder made: new bytes (no metadata), their file extension and size in pixels. */
final readonly class EncodedImage
{
    public function __construct(
        public string $bytes,
        public string $extension,
        public int $width,
        public int $height,
    ) {}
}
