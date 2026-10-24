<?php

namespace App\Domain\OnlineStore\Payments;

/**
 * lz-string's `compressToEncodedURIComponent` (MIT, pieroxy), which DNA's hosted checkout uses to read the payment
 * from its URL (the DNA PHP SDK's `encodeToUrl`). Works on UTF-16 code units like the JavaScript original, so the
 * output is the same for any text; the payment JSON we send is plain ASCII anyway.
 */
final class LzString
{
    private const KEYS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-$';

    private const BITS = 6;

    private int $value = 0;

    private int $position = 0;

    private string $out = '';

    private function __construct() {}

    public static function compressToEncodedURIComponent(string $input): string
    {
        return $input === '' ? '' : (new self)->compress($input);
    }

    private function compress(string $input): string
    {
        $units = array_values(unpack('n*', mb_convert_encoding($input, 'UTF-16BE', 'UTF-8')) ?: []);
        $dictionary = [];
        $toCreate = [];
        $w = '';
        $enlargeIn = 2;
        $dictSize = 3;
        $numBits = 2;

        foreach ($units as $unit) {
            $c = pack('n', $unit);

            if (! isset($dictionary[$c])) {
                $dictionary[$c] = $dictSize++;
                $toCreate[$c] = true;
            }

            $wc = $w.$c;

            if (isset($dictionary[$wc])) {
                $w = $wc;

                continue;
            }

            $this->emitPhrase($w, $dictionary, $toCreate, $numBits, $enlargeIn);
            $this->grow($numBits, $enlargeIn);
            $dictionary[$wc] = $dictSize++;
            $w = $c;
        }

        if ($w !== '') {
            $this->emitPhrase($w, $dictionary, $toCreate, $numBits, $enlargeIn);
            $this->grow($numBits, $enlargeIn);
        }

        $this->write(2, $numBits);

        while (true) {
            $this->value <<= 1;

            if ($this->position === self::BITS - 1) {
                $this->out .= self::KEYS[$this->value];

                break;
            }

            $this->position++;
        }

        return $this->out;
    }

    /**
     * @param  array<string, int>  $dictionary
     * @param  array<string, bool>  $toCreate
     */
    private function emitPhrase(string $w, array $dictionary, array &$toCreate, int &$numBits, int &$enlargeIn): void
    {
        if (! isset($toCreate[$w])) {
            $this->write($dictionary[$w], $numBits);

            return;
        }

        $code = unpack('n', substr($w, 0, 2))[1];

        if ($code < 256) {
            $this->write(0, $numBits);
            $this->write($code, 8);
        } else {
            $this->write(1, $numBits);
            $this->write($code, 16);
        }

        $this->grow($numBits, $enlargeIn);
        unset($toCreate[$w]);
    }

    private function grow(int &$numBits, int &$enlargeIn): void
    {
        $enlargeIn--;

        if ($enlargeIn === 0) {
            $enlargeIn = 1 << $numBits;
            $numBits++;
        }
    }

    /** Writes `$bits` bits of `$value`, lowest first (lz-string's order). */
    private function write(int $value, int $bits): void
    {
        for ($i = 0; $i < $bits; $i++) {
            $this->value = ($this->value << 1) | ($value & 1);

            if ($this->position === self::BITS - 1) {
                $this->position = 0;
                $this->out .= self::KEYS[$this->value];
                $this->value = 0;
            } else {
                $this->position++;
            }

            $value >>= 1;
        }
    }
}
