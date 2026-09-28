<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token's `v` is higher than 1 (`licence.unsupported_version`).
 */
final class UnsupportedVersion extends LicenceTokenException {}
