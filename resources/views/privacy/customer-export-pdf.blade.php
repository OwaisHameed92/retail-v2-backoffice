<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<title>Personal data held about {{ $d['customer']['name'] }}</title>
<style>
    @page { margin: 34px 42px 50px 42px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; line-height: 1.45; color: #0f172a; margin: 0; }
    h1 { font-size: 18px; margin: 0 0 2px 0; letter-spacing: -0.3px; }
    h2 { font-size: 11px; margin: 18px 0 6px 0; text-transform: uppercase; letter-spacing: 0.5px; color: #007048; }
    .muted { color: #64748b; }
    .rule { height: 3px; background: #007048; margin: 12px 0 14px 0; }
    table { border-collapse: collapse; width: 100%; }
    th { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; text-align: left; padding: 5px; border-bottom: 1px solid #cbd5e1; background: #f8fafc; }
    td { padding: 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .facts td { border: none; padding: 2px 0; }
    .facts .k { color: #64748b; width: 32%; }
    .num { text-align: right; white-space: nowrap; }
</style>
</head>
<body>
    <h1>Personal data held about you</h1>
    <div class="muted">{{ $d['business'] }} · generated {{ $d['generatedAt'] }} (UTC)</div>
    <div class="rule"></div>

    <h2>Your details</h2>
    <table class="facts">
        <tr><td class="k">Name</td><td>{{ $d['customer']['name'] ?: '—' }}</td></tr>
        <tr><td class="k">Phone</td><td>{{ $d['customer']['phone'] ?: '—' }}</td></tr>
        <tr><td class="k">Email</td><td>{{ $d['customer']['email'] ?: '—' }}</td></tr>
        <tr><td class="k">Address</td><td>{{ $d['customer']['address'] ?: '—' }}</td></tr>
        <tr><td class="k">Date of birth</td><td>{{ $d['customer']['dateOfBirth'] ?? '—' }}</td></tr>
        <tr><td class="k">Loyalty card</td><td>{{ $d['customer']['cardNo'] ?: '—' }}</td></tr>
        <tr><td class="k">Tier</td><td>{{ $d['customer']['tier'] ?: '—' }}</td></tr>
        <tr><td class="k">Notes</td><td>{{ $d['customer']['notes'] ?: '—' }}</td></tr>
        <tr><td class="k">Customer since</td><td>{{ $d['customer']['createdAt'] ?? '—' }}</td></tr>
        <tr><td class="k">Account balance</td><td>£{{ $d['account']['balance'] }} (positive = owed to the shop)</td></tr>
        <tr><td class="k">Loyalty points</td><td>{{ $d['account']['points'] }}</td></tr>
    </table>

    <h2>Marketing consent</h2>
    <table>
        <tr><th>Channel</th><th>Now</th><th>Since (UTC)</th><th>How</th></tr>
        @foreach ($d['consent']['current'] as $c)
            <tr><td>{{ $c['label'] }}</td><td>{{ ['given' => 'Agreed', 'withdrawn' => 'Not agreed', 'none' => 'Never asked'][$c['state']] ?? $c['state'] }}</td><td>{{ $c['at'] ?? '—' }}</td><td>{{ $c['source'] ?? '—' }}</td></tr>
        @endforeach
    </table>

    <h2>Loyalty points</h2>
    <table class="facts">
        <tr><td class="k">Earned</td><td>{{ $d['loyalty']['earned'] }}</td></tr>
        <tr><td class="k">Spent</td><td>{{ $d['loyalty']['spent'] }}</td></tr>
        <tr><td class="k">Expired</td><td>{{ $d['loyalty']['expired'] }}</td></tr>
        <tr><td class="k">Adjusted</td><td>{{ $d['loyalty']['adjusted'] }}</td></tr>
    </table>

    <h2>Account entries ({{ count($d['ledger']) }})</h2>
    @if (count($d['ledger']) === 0)
        <p class="muted">None.</p>
    @else
        <table>
            <tr><th>Date (UTC)</th><th>Type</th><th>Shop</th><th class="num">Amount</th><th class="num">Points</th></tr>
            @foreach (array_slice($d['ledger'], -200) as $r)
                <tr><td>{{ $r['at'] }}</td><td>{{ $r['typeLabel'] }}</td><td>{{ $r['shop'] }}</td><td class="num">£{{ $r['amount'] }}</td><td class="num">{{ $r['points'] }}</td></tr>
            @endforeach
        </table>
        @if (count($d['ledger']) > 200)
            <p class="muted">The latest 200 are shown here; account-ledger.csv has every entry.</p>
        @endif
    @endif

    <h2>Sales linked to you ({{ count($d['sales']) }})</h2>
    @if (count($d['sales']) === 0)
        <p class="muted">None.</p>
    @else
        <table>
            <tr><th>Receipt</th><th>Completed (UTC)</th><th>Shop</th><th class="num">Total</th></tr>
            @foreach (array_slice($d['sales'], -200) as $s)
                <tr><td>{{ $s['receiptNumber'] }}</td><td>{{ $s['completedAt'] ?? '—' }}</td><td>{{ $s['shop'] ?? '—' }}</td><td class="num">£{{ $s['total'] }}</td></tr>
            @endforeach
        </table>
        @if (count($d['sales']) > 200)
            <p class="muted">The latest 200 are shown here; sales.csv has every sale.</p>
        @endif
    @endif

    <h2>Customer orders ({{ count($d['customerOrders']) }}) and e-receipts ({{ count($d['eReceipts']) }})</h2>
    <p class="muted">Listed in customer-orders.csv and e-receipts.csv.</p>
</body>
</html>
