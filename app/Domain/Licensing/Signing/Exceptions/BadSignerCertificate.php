<?php

namespace App\Domain\Licensing\Signing\Exceptions;

/**
 * The signer certificate (SSPOSCERT1…, contract §17.17) is malformed, not from a configured approver, for
 * another key, expired, or its signature fails. The till reports this as `licence.bad_signer_certificate`.
 */
final class BadSignerCertificate extends LicenceTokenException {}
