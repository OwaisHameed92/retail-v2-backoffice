<?php

namespace App\Domain\Cash\Support;

use App\Domain\Shared\Support\Money;

/**
 * `ZReport.totalsJson` (a JSON string of the till's PascalCase `ZReportDto`, DASHBOARD.md §2.4), read as sent: the
 * tender lines, the variance total, the till's own warning flag and the alert amount it used. Never recomputed.
 */
final readonly class ZTotals
{
    /**
     * @param  list<array{paymentTypeId: string|null, name: string, expected: string|null, declared: string|null, terminal: string|null, variance: string|null, exceedsThreshold: bool}>  $tenders
     */
    public function __construct(
        public array $tenders,
        public ?string $variance,
        public bool $warning,
        public ?string $alertOver,
        public bool $readable,
    ) {}

    public static function parse(?string $json): self
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        if (! is_array($data)) {
            return new self([], null, false, null, false);
        }

        $tenders = [];

        foreach (is_array($data['Tenders'] ?? null) ? $data['Tenders'] : [] as $t) {
            if (! is_array($t)) {
                continue;
            }

            $tenders[] = [
                'paymentTypeId' => is_string($t['PaymentTypeId'] ?? null) ? $t['PaymentTypeId'] : null,
                'name' => is_string($t['PaymentTypeName'] ?? null) && $t['PaymentTypeName'] !== '' ? $t['PaymentTypeName'] : 'Unknown',
                'expected' => self::money($t['Expected'] ?? null),
                'declared' => self::money($t['Declared'] ?? null),
                'terminal' => self::money($t['TerminalTotal'] ?? null),
                'variance' => self::money($t['Variance'] ?? null),
                'exceedsThreshold' => ($t['ExceedsThreshold'] ?? false) === true,
            ];
        }

        return new self($tenders, self::money($data['VarianceTotal'] ?? null), ($data['HasVarianceWarning'] ?? false) === true, self::money($data['VarianceAlertOver'] ?? null), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['tenders' => $this->tenders, 'variance' => $this->variance, 'warning' => $this->warning, 'alertOver' => $this->alertOver, 'readable' => $this->readable];
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) ? Money::normalise($value) : null;
    }
}
