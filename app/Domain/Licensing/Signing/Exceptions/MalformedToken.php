<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token is not a well-formed `SSPOS1.` licence token (prefix, parts, base64url or payload JSON).
 */
final class MalformedToken extends LicenceTokenException {}
