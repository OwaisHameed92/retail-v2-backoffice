# Licence key format

Our own e-mailed licence key (`SSP-XXXX-XXXX-XXXX-XXXX`), sent by the till as `licenceKey` to
`POST /api/v1/licence/activate` (contract v1.3.1 §17.15.1: `^[A-Z0-9-]{8,64}$`, layout is the portal's choice).
Implemented by `App\Domain\Licensing\LicenceKey`; test vectors in `tests/Unit/Licensing/LicenceKeyTest.php`.

The till can check a typed key offline before calling `activate`, so a typo gets "check the key" at once
instead of a round trip and a 404.

**Alphabet** (Crockford base32, 32 symbols, no I, L, O, U), value = position:

```
0123456789ABCDEFGHJKMNPQRSTVWXYZ
```

`0`=0 … `9`=9, `A`=10, `B`=11, … `H`=17, `J`=18, `K`=19, `M`=20, `N`=21, `P`=22, `Q`=23, `R`=24, `S`=25, `T`=26,
`V`=27, `W`=28, `X`=29, `Y`=30, `Z`=31.

**Shape:** `SSP-` + 4 groups of 4 = 16 characters. Characters 1–15 are random (15 random bytes, low 5 bits of
each); character 16 is the check character.

**Normalising what a person typed** (do this before checking or sending):

1. Upper-case.
2. Remove spaces, tabs, `-`, `_` and `.`.
3. Map `O` → `0`, `I` → `1`, `L` → `1`.
4. If the result is 19 characters and starts with `SSP`, drop the `SSP`.
5. It must now be exactly 16 alphabet characters (a `U` or any other symbol is invalid).

**Check character: Luhn mod 32** (the "Luhn mod N" algorithm with N = 32):

```text
function checkCharacter(payload15):            // the first 15 characters
    factor = 2; sum = 0
    for i from 14 down to 0:                   // right to left
        addend = factor * value(payload15[i])
        sum   += (addend div 32) + (addend mod 32)
        factor = (factor == 2) ? 1 : 2
    return ALPHABET[(32 - (sum mod 32)) mod 32]

function isValid(body16):                      // normalised 16 characters
    factor = 1; sum = 0
    for i from 15 down to 0:                   // the check character is not doubled
        addend = factor * value(body16[i])
        sum   += (addend div 32) + (addend mod 32)
        factor = (factor == 2) ? 1 : 2
    return sum mod 32 == 0
```

It catches every single wrong character and every swap of two neighbours except `0Z` ↔ `Z0`.

Worked example, payload `7K2Q9DMF3XRAP8T` (right to left, factor starts at 2):

| Char | T | 8 | P | A | R | X | 3 | F | M | D | 9 | Q | 2 | K | 7 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Value | 26 | 8 | 22 | 10 | 24 | 29 | 3 | 15 | 20 | 13 | 9 | 23 | 2 | 19 | 7 |
| Factor | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 |
| Addend (div + mod) | 21 | 8 | 13 | 10 | 17 | 29 | 6 | 15 | 9 | 13 | 18 | 23 | 4 | 19 | 14 |

Sum = 219, 219 mod 32 = 27, (32 − 27) mod 32 = 5 → check character `5` → key `SSP-7K2Q-9DMF-3XRA-P8T5`.

**Test vectors** (also in the portal's tests, `tests/Unit/Licensing/LicenceKeyTest.php`):

| First 15 | Check | Key | Valid? |
|---|---|---|---|
| `7K2Q9DMF3XRAP8T` | `5` | `SSP-7K2Q-9DMF-3XRA-P8T5` | yes |
| `4HWCJ6ZB81MEQV5` | `H` | `SSP-4HWC-J6ZB-81ME-QV5H` | yes |
| `2NRXT7KP5G0ADYF` | `6` | `SSP-2NRX-T7KP-5G0A-DYF6` | yes |
| `123456789ABCDEF` | `8` | `SSP-1234-5678-9ABC-DEF8` | yes |
| `0123456789ABCDE` | `Z` | `SSP-0123-4567-89AB-CDEZ` | yes |
| `000000000000000` | `0` | `SSP-0000-0000-0000-0000` | yes |
| | | `SSP-7K2Q-9DMF-3XRA-P8T6` | no (check character) |
| | | `SSP-7K2Q-9DMF-3XRA-8PT5` | no (swapped neighbours) |
| | | `SSP-7K2Q-9DMF-3XRA-P8TU` | no (`U` is not in the alphabet) |
| | | `ssp 7k2q 9dmf 3xra p8t5` | yes (normalises to the first row) |
| | | `SSP-OI23-4567-89AB-CDEZ` | yes (`O`→`0`, `I`→`1`: the fifth row) |

**Portal storage:** `key_hash` = HMAC-SHA256 (hex) of the normalised 16 characters, keyed with the portal's
APP_KEY (previous APP_KEYs are also tried, so a key rotation does not lose licences); `key_last4` = the last 4
characters. The plain key exists only in the reply that created it and in the welcome / "new licence key"
emails. Admin screens show `SSP-••••-••••-••••-P8T5`.

