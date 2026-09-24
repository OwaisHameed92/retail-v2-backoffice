<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token's signature is valid but its claims are not acceptable (e.g. wrong iss).
 */
final class InvalidClaims extends LicenceTokenException {}
