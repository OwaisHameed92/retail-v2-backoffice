<?php

namespace App\Domain\Shared\Support;

/**
 * Till app versions (`X-SSPOS-App-Version`, `appVersion`): semantic versions, today `0.1.x` (contract v1.4.1 §3,
 * ANSWERS-2026-09-29 §3). Compared as semver: numeric major.minor.patch, a pre-release (`-beta.2`) sorts before its
 * release, build metadata (`+5`) is ignored.
 */
final class AppVersion
{
    private const PATTERN = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, trim($version)) === 1;
    }

    /** -1, 0 or 1 like the spaceship operator; an unreadable version sorts first. */
    public static function compare(string $a, string $b): int
    {
        $x = self::parse($a);
        $y = self::parse($b);

        if ($x === null || $y === null) {
            return ($x !== null) <=> ($y !== null);
        }

        $release = [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]];

        if ($release !== 0 || $x[3] === $y[3]) {
            return $release;
        }

        // A release is newer than any of its pre-releases.
        if ($x[3] === null || $y[3] === null) {
            return $x[3] === null ? 1 : -1;
        }

        return self::comparePreRelease($x[3], $y[3]);
    }

    /**
     * Whether `$version` is one of `$patterns`: an exact version ("0.1.4", build metadata ignored) or a prefix
     * with a wildcard ("0.1.*").
     *
     * @param  list<string>  $patterns
     */
    public static function matchesAny(string $version, array $patterns): bool
    {
        $version = trim($version);

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with(ltrim($version, 'v'), rtrim(ltrim($pattern, 'v'), '*'))) {
                    return true;
                }

                continue;
            }

            if (self::parse($version) !== null && self::compare($version, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string|null}|null
     */
    private static function parse(string $version): ?array
    {
        if (preg_match(self::PATTERN, trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), ($m[4] ?? '') === '' ? null : $m[4]];
    }

    private static function comparePreRelease(string $a, string $b): int
    {
        $x = explode('.', $a);
        $y = explode('.', $b);

        foreach ($x as $i => $part) {
            if (! isset($y[$i])) {
                return 1;
            }

            $cmp = ctype_digit($part) && ctype_digit($y[$i]) ? (int) $part <=> (int) $y[$i] : strcmp($part, $y[$i]) <=> 0;

            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return count($x) < count($y) ? -1 : 0;
    }
}
