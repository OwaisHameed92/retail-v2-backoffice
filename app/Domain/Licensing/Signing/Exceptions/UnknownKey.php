<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token's kid is not an active or recently retired signing key.
 */
final class UnknownKey extends LicenceTokenException {}
