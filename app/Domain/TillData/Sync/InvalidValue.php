<?php

namespace App\Domain\TillData\Sync;

use RuntimeException;

/**
 * A payload value that does not match its schema type. Becomes part of a `payload.invalid` rejection.
 */
final class InvalidValue extends RuntimeException {}
