<?php

namespace App\Domain\Billing\Enums;

/**
 * What an invoice line pays for (audit OSA-30): a till's licence, a branch (per-branch pricing), the online store
 * add-on, a setup fee, or a line an admin typed on a draft. Stored in `invoice_lines.kind`; lines written before it existed may
 * have null (InvoiceLine::isOnlineStore() falls back to the old rule for those).
 */
enum InvoiceLineKind: string
{
    case Licence = 'licence';
    case Branch = 'branch';
    case OnlineStore = 'onlineStore';
    case SetupFee = 'setupFee';
    case Manual = 'manual';
}
