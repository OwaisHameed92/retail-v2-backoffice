<?php

namespace App\Domain\OnlineStore\Payments;

use RuntimeException;

/** DNA could not start a card payment (unreachable, refused the credentials or the payment). Never holds a secret. */
final class DnaPaymentException extends RuntimeException {}
