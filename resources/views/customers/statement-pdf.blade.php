<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<title>Statement for {{ $s['customer']['name'] }}</title>
<style>
    @page { margin: 34px 42px 60px 42px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 1.45; color: #0f172a; margin: 0; }
    .muted { color: #64748b; }
    .small { font-size: 8.5px; }
    .right { text-align: right; }
    table { border-collapse: collapse; width: 100%; }
    .header td { vertical-align: top; }
    .business { font-size: 16px; font-weight: bold; letter-spacing: -0.2px; }
    .doc-title { font-size: 20px; font-weight: bold; letter-spacing: -0.3px; margin: 0; }
    .rule { height: 3px; background: #007048; margin: 16px 0 18px 0; }
    .parties td { vertical-align: top; width: 50%; padding-right: 14px; }
    .label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; margin-bottom: 4px; }
    .party-name { font-weight: bold; font-size: 10.5px; }
    .facts td { padding: 1px 0; }
    .facts .k { color: #64748b; padding-right: 10px; }
    .summary { margin-top: 18px; }
    .summary td { width: 25%; border: 1px solid #e2e8f0; background: #f8fafc; padding: 8px 10px; }
    .summary .v { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .lines { margin-top: 20px; }
    .lines th { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; text-align: left; padding: 7px 6px; border-bottom: 1px solid #cbd5e1; background: #f8fafc; }
    .lines td { padding: 7px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines .carried td { background: #f8fafc; font-weight: bold; }
    .empty { padding: 18px 6px; text-align: center; color: #64748b; }
    .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 6px; }
</style>
</head>
<body>
@php
    $money = fn (string $v) => \App\Domain\Billing\Support\BillingFormat::money($v);
    $points = fn (int $v) => number_format($v);
    $b = $s['business'];
    $c = $s['customer'];
    $closing = $s['closing']['balance'];
    $owes = \App\Domain\Shared\Support\Money::compare($closing, '0') > 0;
    $credit = \App\Domain\Shared\Support\Money::isNegative($closing);
@endphp

<div class="footer">
    {{ $b['name'] }}@if ($b['address']) · {{ $b['address'] }}@endif
    @if ($b['phone']) · {{ $b['phone'] }}@endif
    @if ($b['email']) · {{ $b['email'] }}@endif
    @if ($b['vatNumber']) · VAT no. {{ $b['vatNumber'] }}@endif
</div>

<table class="header">
    <tr>
        <td>
            <div class="business">{{ $b['name'] }}</div>
            @if ($b['address'])<div class="muted small">{{ $b['address'] }}</div>@endif
        </td>
        <td class="right">
            <div class="doc-title">Account statement</div>
            <div class="muted">{{ $s['period'] }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="parties">
    <tr>
        <td>
            <div class="label">Statement for</div>
            <div class="party-name">{{ $c['name'] }}</div>
            @if ($c['address'])<div>{{ $c['address'] }}</div>@endif
            @if ($c['email'])<div class="muted">{{ $c['email'] }}</div>@endif
        </td>
        <td>
            <table class="facts">
                @if ($c['cardNo'])<tr><td class="k">Card number</td><td>{{ $c['cardNo'] }}</td></tr>@endif
                <tr><td class="k">Period</td><td>{{ $s['period'] }}</td></tr>
                <tr><td class="k">Issued</td><td>{{ $s['issued'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td><div class="label">Opening balance</div><div class="v">{{ $money($s['opening']['balance']) }}</div></td>
        <td><div class="label">Account sales</div><div class="v">{{ $money($s['totals']['charges']) }}</div></td>
        <td><div class="label">Payments and credits</div><div class="v">{{ $money($s['totals']['credits']) }}</div></td>
        <td>
            <div class="label">{{ $owes ? 'Amount owed' : ($credit ? 'In credit' : 'Closing balance') }}</div>
            <div class="v">{{ $money($credit ? ltrim($closing, '-') : $closing) }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 16%;">Date</th>
            <th>Details</th>
            <th style="width: 16%;">Shop</th>
            <th class="num" style="width: 11%;">Amount</th>
            <th class="num" style="width: 11%;">Balance</th>
            <th class="num" style="width: 9%;">Points</th>
        </tr>
    </thead>
    <tbody>
        <tr class="carried">
            <td colspan="4">Brought forward</td>
            <td class="num">{{ $money($s['opening']['balance']) }}</td>
            <td class="num">{{ $points($s['opening']['points']) }}</td>
        </tr>
        @forelse ($s['rows'] as $row)
            <tr>
                <td>{{ \Carbon\CarbonImmutable::parse($row['at'])->setTimezone('Europe/London')->format('j M Y H:i') }}</td>
                <td>{{ $row['typeLabel'] }}@if ($row['note'])<div class="muted small">{{ $row['note'] }}</div>@endif</td>
                <td>{{ $row['shop'] }}</td>
                <td class="num">{{ \App\Domain\Shared\Support\Money::isZero($row['amount']) ? '' : $money($row['amount']) }}</td>
                <td class="num">{{ $money($row['balanceAfter']) }}</td>
                <td class="num">{{ $row['points'] === 0 ? '' : ($row['points'] > 0 ? '+' : '').$points($row['points']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No account activity in this period.</td></tr>
        @endforelse
        <tr class="carried">
            <td colspan="4">Closing balance</td>
            <td class="num">{{ $money($closing) }}</td>
            <td class="num">{{ $points($s['closing']['points']) }}</td>
        </tr>
    </tbody>
</table>

<p class="muted small" style="margin-top: 14px;">
    A positive balance is what you owe; a negative balance is credit on your account. Points: {{ $points($s['totals']['pointsEarned']) }} earned and {{ $points($s['totals']['pointsUsed']) }} used in this period, {{ $points($s['closing']['points']) }} available at the end of it.
    Questions about this statement? Contact {{ $b['name'] }}@if ($b['phone']) on {{ $b['phone'] }}@endif.
</p>
</body>
</html>
