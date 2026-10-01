<?php

namespace App\Domain\Accounts\Export;

/**
 * The mapping in force for one package (gap #8): the business's own rows over ExportDefaults. Runs in the company
 * scope, so another business's mapping is never used.
 */
final readonly class ExportMappings
{
    /**
     * @param  array<int|string, string>  $accounts  our code => their code (own and default; numeric codes are int keys)
     * @param  array<int|string, string>  $ownAccounts  our code => their code (the business's own only)
     * @param  array<string, array{0: string, 1: string}>  $vat  our VAT code => [sales, purchases]
     * @param  array<string, array{0: string, 1: string}>  $ownVat
     */
    public function __construct(
        public ExportTarget $target,
        public array $accounts,
        public array $ownAccounts,
        public array $vat,
        public array $ownVat,
    ) {}

    public static function for(ExportTarget $target): self
    {
        $rows = AccountingExportMapping::query()->where('target', $target->value)->get();
        $ownAccounts = [];
        $ownVat = [];

        foreach ($rows as $row) {
            if ($row->kind === AccountingExportMapping::KIND_ACCOUNT) {
                $ownAccounts[$row->our_code] = $row->their_code;
            } else {
                $ownVat[$row->our_code] = [$row->their_code, $row->their_purchase_code ?? $row->their_code];
            }
        }

        // array_replace, not spread: account codes are numeric keys, which a spread would renumber.
        return new self($target, array_replace(ExportDefaults::accounts($target), $ownAccounts), $ownAccounts, array_replace(ExportDefaults::vat($target), $ownVat), $ownVat);
    }

    /** Their account code, or null when neither the business nor the defaults map it. */
    public function account(string $code): ?string
    {
        return $this->accounts[$code] ?? null;
    }

    /** Their tax code for a line: by our VAT code (null = no VAT rate) and whether it is a sales line. */
    public function tax(?string $vatCode, bool $sales): string
    {
        $pair = $this->vat[$vatCode ?? ExportDefaults::NO_VAT] ?? $this->vat[ExportDefaults::NO_VAT];

        return $sales ? $pair[0] : $pair[1];
    }
}
