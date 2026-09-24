<?php

namespace App\Domain\Licensing\Exceptions;

use InvalidArgumentException;

/**
 * A typed licence key is not in the `SSP-XXXX-XXXX-XXXX-XXXX` format or its check character is wrong.
 * The message never contains the key.
 */
final class InvalidLicenceKey extends InvalidArgumentException
{
    public static function format(): self
    {
        return new self('A licence key has 16 letters and numbers after “SSP”, like SSP-7K2Q-9DMF-3XRA-P8T5.');
    }

    public static function checkCharacter(): self
    {
        return new self('This licence key has a typo. Check each character and try again.');
    }
}
