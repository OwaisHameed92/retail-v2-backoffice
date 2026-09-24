<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The token is not a well-formed sspos licence JWS (structure, encoding, alg, typ or header).
 */
final class MalformedToken extends LicenceTokenException {}
