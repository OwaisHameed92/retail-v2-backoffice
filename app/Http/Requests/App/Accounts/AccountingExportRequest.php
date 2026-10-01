<?php

namespace App\Http\Requests\App\Accounts;

use App\Domain\Accounts\Export\ExportTarget;
use App\Domain\Accounts\Export\JournalSummary;

/**
 * The accounting export screens (gap #8): the Accounts filters (dates, shop, refund fix) plus the package
 * (`target`, default Xero) and the grouping (`grouping`: daily or period, default daily), read leniently. The
 * route checks `accounts.view` and `accounts.export`.
 */
class AccountingExportRequest extends AccountsFilterRequest
{
    public function target(): ExportTarget
    {
        return ExportTarget::tryFrom((string) $this->query('target')) ?? ExportTarget::Xero;
    }

    public function grouping(): string
    {
        $grouping = (string) $this->query('grouping');

        return in_array($grouping, JournalSummary::GROUPINGS, true) ? $grouping : 'daily';
    }
}
