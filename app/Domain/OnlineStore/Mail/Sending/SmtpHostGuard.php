<?php

namespace App\Domain\OnlineStore\Mail\Sending;

use Closure;

/**
 * SSRF guard for a store's own SMTP server (OS-10): the portal must never be made to connect to itself, the private
 * network or a cloud metadata address. A host is refused when it is not a plain host name or IP, is a local-only name
 * (`localhost`, `*.local`, `*.internal`, one label), or any address it resolves to is loopback, private, link-local,
 * carrier-grade NAT or reserved. At send time the connection goes to the checked address (pinned), so a DNS change
 * between the check and the connection cannot point it somewhere else.
 *
 *     $problem = SmtpHostGuard::problem('smtp.example.com');   // null = fine, else a message for the form
 *     $ip = SmtpHostGuard::safeAddress('smtp.example.com');    // throws StoreSmtpUnavailable
 *
 * Tests swap the DNS lookup with resolveUsing().
 */
final class SmtpHostGuard
{
    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.home.arpa', '.intranet', '.corp', '.localdomain', '.test', '.invalid', '.example'];

    /** @var (Closure(string): list<string>)|null */
    private static ?Closure $resolver = null;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => its IP addresses; null = real DNS
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Why the host cannot be used, in plain words, or null when it is fine. */
    public static function problem(string $host): ?string
    {
        $host = strtolower(trim($host));

        if (! self::wellFormed($host)) {
            return 'Enter the server name only, for example smtp.yourdomain.co.uk.';
        }

        if (self::localName($host)) {
            return 'Use your email provider\'s public server name, not a local or internal one.';
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            return 'We could not find that server. Check its name.';
        }

        foreach ($addresses as $ip) {
            if (! self::publicIp($ip)) {
                return 'That server is on a private or internal network. Use your email provider\'s public server.';
            }
        }

        return null;
    }

    /**
     * The address to connect to: the host checked again and resolved now. IPv4 is preferred.
     *
     * @throws StoreSmtpUnavailable when the host is refused or cannot be found
     */
    public static function safeAddress(string $host): string
    {
        $problem = self::problem($host);

        if ($problem !== null) {
            throw new StoreSmtpUnavailable($problem);
        }

        $host = strtolower(trim($host));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $addresses = self::resolve($host);
        $v4 = array_values(array_filter($addresses, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return $v4[0] ?? $addresses[0];
    }

    public static function publicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mapped = substr($ip, 7);

            return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && self::publicIp($mapped);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = (int) ip2long($ip);

            // 100.64.0.0/10 carrier-grade NAT, 192.0.0.0/24 IETF, 198.18.0.0/15 benchmarking.
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);

                if (($long & $mask) === ((int) ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6: unique local fc00::/7, link-local fe80::/10, documentation 2001:db8::/32 and NAT64 64:ff9b::/96.
        $packed = (string) inet_pton($ip);
        $first = ord($packed[0] ?? "\0");
        $second = ord($packed[1] ?? "\0");

        return ($first & 0xFE) !== 0xFC && ! ($first === 0xFE && ($second & 0xC0) === 0x80)
            && ! str_starts_with(bin2hex($packed), '20010db8') && ! str_starts_with(bin2hex($packed), '0064ff9b');
    }

    private static function wellFormed(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) === 1;
    }

    private static function localName(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_map(fn (array $r) => (string) ($r['ipv6'] ?? ''), @dns_get_record($host, DNS_AAAA) ?: []);

        return array_values(array_unique(array_filter([...$v4, ...$v6], fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)));
    }
}
