<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token's signature does not match its header and payload.
 */
final class InvalidSignature extends LicenceTokenException {}
