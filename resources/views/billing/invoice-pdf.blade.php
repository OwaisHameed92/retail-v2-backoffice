<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Shared\Country\Country::class)->dateLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $doc['title'] }}</title>
<style>
    @page { margin: 34px 42px 60px 42px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 1.45; color: #0f172a; margin: 0; }
    .muted { color: #64748b; }
    .small { font-size: 8.5px; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    table { border-collapse: collapse; width: 100%; }
    .header td { vertical-align: top; }
    .logo { height: 34px; }
    .doc-title { font-size: 22px; font-weight: bold; letter-spacing: -0.3px; color: #0f172a; margin: 0; }
    .doc-number { font-size: 11px; color: #334155; margin-top: 2px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 8.5px; font-weight: bold; margin-top: 6px; }
    .badge-paid { background: #e7f9e7; color: #0a7a0a; }
    .badge-overdue { background: #fdecec; color: #b42318; }
    .badge-void { background: #f1f5f9; color: #475569; }
    .badge-draft { background: #f1f5f9; color: #475569; }
    .badge-open { background: #eaf1ff; color: #0147c9; }
    .rule { height: 3px; background: #015cfc; margin: 16px 0 18px 0; }
    .parties td { vertical-align: top; width: 33%; padding-right: 14px; }
    .label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; margin-bottom: 4px; }
    .party-name { font-weight: bold; font-size: 10.5px; }
    .facts td { padding: 1px 0; }
    .facts .k { color: #64748b; padding-right: 10px; }
    .lines { margin-top: 22px; }
    .lines th { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; text-align: left; padding: 7px 6px; border-bottom: 1px solid #cbd5e1; background: #f8fafc; }
    .lines td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .lines .num { text-align: right; white-space: nowrap; }
    .totals { margin-top: 12px; width: 46%; margin-left: 54%; }
    .totals td { padding: 4px 6px; }
    .totals .k { text-align: right; color: #475569; }
    .totals .v { text-align: right; width: 110px; white-space: nowrap; }
    .totals .grand td { font-size: 12px; font-weight: bold; border-top: 1.5px solid #0f172a; padding-top: 7px; }
    .totals .due td { font-size: 11px; font-weight: bold; color: #015cfc; }
    .section { margin-top: 20px; }
    .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; background: #f8fafc; }
    .stamp { position: absolute; top: 360px; left: 130px; width: 460px; text-align: center; font-size: 84px; font-weight: bold; letter-spacing: 8px; color: #0f172a; opacity: 0.06; transform: rotate(-24deg); }
    .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 6px; }
</style>
</head>
<body>
@php
    $vatNo = app(\App\Domain\Shared\Country\Country::class)->vatNumberPrefix();
    // Pak POS pack: our own tax number keeps its name (NTN) while the customer's `vat_number` is the STRN; GB as before.
    $sellerVatNo = app(\App\Domain\Shared\Country\Country::class)->sellerIdLabel('vatNumber') ?? $vatNo;
    $tax = app(\App\Domain\Shared\Country\Country::class)->taxName();
    $badgeClass = match ($doc['status']) {
        'paid' => 'badge-paid',
        'overdue' => 'badge-overdue',
        'void' => 'badge-void',
        'draft' => 'badge-draft',
        default => 'badge-open',
    };
    $seller = $doc['seller'];
    $billTo = $doc['billTo'];
@endphp

@if (in_array($doc['status'], ['draft', 'void', 'paid'], true))
    <div class="stamp">{{ strtoupper($doc['status'] === 'void' ? 'Void' : $doc['statusLabel']) }}</div>
@endif

<div class="footer">
    {{ $seller['legalName'] }}@if ($seller['companyNumber']) · {{ \App\Domain\Shared\Country\LocalText::registration($seller['companyNumber']) }}@endif
    @if ($seller['vatNumber']) · {{ $sellerVatNo }} {{ $seller['vatNumber'] }}@endif
    @if (! empty($seller['strn'])) · STRN {{ $seller['strn'] }}@endif
    @if ($seller['email']) · {{ $seller['email'] }}@endif
</div>

<table class="header">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="Switch & Save">
            @else
                <div class="doc-title">{{ $seller['name'] }}</div>
            @endif
            <div class="muted small" style="margin-top: 6px;">Smart Solutions for Smart Businesses</div>
        </td>
        <td class="right">
            <div class="doc-title">Invoice</div>
            <div class="doc-number">{{ $doc['number'] ?? 'Draft, not yet issued' }}</div>
            <span class="badge {{ $badgeClass }}">{{ $doc['statusLabel'] }}</span>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="parties">
    <tr>
        <td>
            <div class="label">From</div>
            <div class="party-name">{{ $seller['legalName'] }}</div>
            @foreach ($seller['address'] as $line)
                <div>{{ $line }}</div>
            @endforeach
            @if ($seller['email'])<div class="muted">{{ $seller['email'] }}</div>@endif
            @if ($seller['phone'])<div class="muted">{{ $seller['phone'] }}</div>@endif
            @if ($seller['vatNumber'])<div class="muted">{{ $sellerVatNo }} {{ $seller['vatNumber'] }}</div>@endif
            @if (! empty($seller['strn']))<div class="muted">STRN {{ $seller['strn'] }}</div>@endif
        </td>
        <td>
            <div class="label">Bill to</div>
            <div class="party-name">{{ $billTo['name'] }}</div>
            @foreach ($billTo['address'] as $line)
                <div>{{ $line }}</div>
            @endforeach
            @if ($billTo['vatNumber'])<div class="muted">{{ $vatNo }} {{ $billTo['vatNumber'] }}</div>@endif
        </td>
        <td>
            <table class="facts">
                <tr><td class="k">Invoice date</td><td class="right">{{ $doc['issueDate'] ?? 'Not issued' }}</td></tr>
                <tr><td class="k">Due date</td><td class="right">{{ $doc['dueDate'] ?? '—' }}</td></tr>
                <tr><td class="k">Period</td><td class="right nowrap">{{ $doc['period'] }}</td></tr>
                <tr><td class="k">Billing</td><td class="right">{{ $doc['cycle'] }}</td></tr>
                @if ($doc['reference'])<tr><td class="k">Reference</td><td class="right">{{ $doc['reference'] }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit price</th>
            @if ($doc['hasVat'])
                <th class="num">Net</th>
                <th class="num">{{ $tax }} {{ $doc['vatRate'] }}</th>
            @endif
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($doc['lines'] as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['unitPrice'] }}</td>
                @if ($doc['hasVat'])
                    <td class="num">{{ $line['net'] }}</td>
                    <td class="num">{{ $line['vat'] }}</td>
                @endif
                <td class="num">{{ $line['gross'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="k">Subtotal</td><td class="v">{{ $doc['subtotal'] }}</td></tr>
    @if ($doc['hasVat'])
        <tr><td class="k">{{ $tax }} at {{ $doc['vatRate'] }}</td><td class="v">{{ $doc['vatTotal'] }}</td></tr>
    @endif
    <tr class="grand"><td class="k">Total</td><td class="v">{{ $doc['total'] }}</td></tr>
    @if ($doc['hasPayments'])
        <tr><td class="k">Paid</td><td class="v">−{{ $doc['amountPaid'] }}</td></tr>
    @endif
    @if ($doc['hasCredits'])
        <tr><td class="k">Credited</td><td class="v">−{{ $doc['amountCredited'] }}</td></tr>
    @endif
    @if ($doc['status'] !== 'void')
        <tr class="due"><td class="k">{{ $doc['status'] === 'paid' ? 'Balance' : 'Amount due' }}</td><td class="v">{{ $doc['balance'] }}</td></tr>
    @endif
</table>

@if ($doc['status'] === 'void')
    <div class="section box">
        <strong>This invoice is void</strong>@if ($doc['voidedOn']) since {{ $doc['voidedOn'] }}@endif. Nothing is owed on it.
        @if ($doc['voidReason'])<div class="muted">Reason: {{ $doc['voidReason'] }}</div>@endif
    </div>
@endif

@if (count($doc['payments']) > 0 || count($doc['creditNotes']) > 0)
    <div class="section">
        <div class="label">Payments and credits</div>
        <table class="lines" style="margin-top: 4px;">
            @foreach ($doc['payments'] as $payment)
                <tr>
                    <td>{{ $payment['date'] }}</td>
                    <td>{{ $payment['method'] }} payment {{ $payment['number'] }}</td>
                    <td class="num">{{ $payment['amount'] }}</td>
                </tr>
            @endforeach
            @foreach ($doc['creditNotes'] as $note)
                <tr>
                    <td>{{ $note['date'] }}</td>
                    <td>Credit note {{ $note['number'] }}: {{ $note['reason'] }}</td>
                    <td class="num">{{ $note['total'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

@if (! in_array($doc['status'], ['paid', 'void'], true))
    <div class="section box">
        <div class="label">How to pay</div>
        @if (! empty($doc['howToPay']))
        {{ $doc['howToPay'] }}
        @else
        We take cash, or pay by bank transfer quoting&nbsp;<strong>{{ $doc['reference'] ?? 'the invoice number' }}</strong>&nbsp;as the reference.
        @endif
        Your licences are renewed as soon as the invoice is paid.
        @if (count($doc['bank']) > 0)
            <div style="margin-top: 6px;">
                @foreach ($doc['bank'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </div>
        @endif
    </div>
@elseif ($doc['status'] === 'paid')
    <div class="section box">
        <strong>Paid in full</strong>@if ($doc['paidOn']) on {{ $doc['paidOn'] }}@endif. Thank you.
    </div>
@endif

@if ($doc['notes'])
    <div class="section">
        <div class="label">Notes</div>
        <div>{!! nl2br(e($doc['notes'])) !!}</div>
    </div>
@endif
</body>
</html>
