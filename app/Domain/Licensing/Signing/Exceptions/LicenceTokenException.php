<?php

namespace App\Domain\Licensing\Signing\Exceptions;

use RuntimeException;

/**
 * Base for every reason a licence token is rejected. Messages never contain the token or any key material.
 */
abstract class LicenceTokenException extends RuntimeException {}
