<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Shared\Country\LocalText;

/**
 * The accounting packages journals can be exported to (gap #8), each with its own CSV import format.
 */
enum ExportTarget: string
{
    case Xero = 'xero';
    case QuickBooks = 'quickbooks';
    case Sage50 = 'sage50';
    case SageAccounting = 'sageAccounting';

    public function label(): string
    {
        return match ($this) {
            self::Xero => 'Xero',
            self::QuickBooks => 'QuickBooks Online',
            self::Sage50 => 'Sage 50 Accounts',
            self::SageAccounting => 'Sage Accounting',
        };
    }

    /** Where the file is imported, for the screen's help text. */
    public function importHelp(): string
    {
        return match ($this) {
            self::Xero => 'In Xero: Accounting → Manual journals → Import. Each journal is one narration.',
            self::QuickBooks => 'In QuickBooks Online: Settings → Import data → Journal entries ('.(LocalText::region() === 'UK' ? 'UK dates, ' : 'dates ').'dd/mm/yyyy).',
            self::Sage50 => 'In Sage 50: File → Import → Audit trail transactions. JD = journal debit, JC = journal credit.',
            self::SageAccounting => 'In Sage Accounting: Settings → Import data → Journals (or Accounting → Journals → Import).',
        };
    }

    public function format(): JournalFormat
    {
        return match ($this) {
            self::Xero => new Formats\XeroFormat,
            self::QuickBooks => new Formats\QuickBooksFormat,
            self::Sage50 => new Formats\Sage50Format,
            self::SageAccounting => new Formats\SageAccountingFormat,
        };
    }
}
