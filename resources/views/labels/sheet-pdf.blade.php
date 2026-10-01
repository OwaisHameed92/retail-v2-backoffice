@php
    /** @var array<string, mixed> $stock */
    $w = $stock['width'];
    $h = $stock['height'];
    $s = $scale;
    $pad = round(1.6 * $s, 2);
    $band = round(6 * $s, 2);
    $barcodeW = round($w * 0.4, 2);
    $barcodeH = round($h * 0.24, 2);
    $tiny = $h < 28;
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<title>Shelf labels</title>
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #000; }
    .page { position: relative; width: {{ $stock['pageWidth'] }}mm; height: {{ $stock['pageHeight'] }}mm; overflow: hidden; page-break-after: always; }
    .page.last { page-break-after: auto; }
    .label { position: absolute; width: {{ $w }}mm; height: {{ $h }}mm; overflow: hidden; }
    .band { position: absolute; left: 0; top: 0; width: {{ $w }}mm; height: {{ $band }}mm; background: #FFD83D; text-align: center;
        font-weight: bold; font-size: {{ round(7.5 * $s, 1) }}pt; line-height: 1; padding-top: {{ round(1.5 * $s, 2) }}mm; white-space: nowrap; overflow: hidden; }
    .name { position: absolute; left: {{ $pad }}mm; width: {{ $w - 2 * $pad }}mm; font-size: {{ round(($tiny ? 6.5 : 7.5) * $s, 1) }}pt;
        line-height: 1.15; max-height: {{ round(($tiny ? 1 : 2) * 1.15 * ($tiny ? 6.5 : 7.5) * $s * 0.3528, 2) }}mm; overflow: hidden; font-weight: bold; }
    .offer { position: absolute; left: {{ $pad }}mm; width: {{ $w - 2 * $pad }}mm; font-size: {{ round(6.5 * $s, 1) }}pt; font-weight: bold; color: #B00020; white-space: nowrap; overflow: hidden; }
    .price { position: absolute; right: {{ $pad }}mm; font-size: {{ round(($tiny ? 17 : 23) * $s, 1) }}pt; font-weight: bold; line-height: 1; text-align: right; white-space: nowrap; }
    .unit { position: absolute; right: {{ $pad }}mm; font-size: {{ round(5.5 * $s, 1) }}pt; text-align: right; white-space: nowrap; }
    .foot { position: absolute; right: {{ $pad }}mm; bottom: {{ $pad * 0.6 }}mm; font-size: {{ round(4.3 * $s, 1) }}pt; color: #333; text-align: right; white-space: nowrap; }
    .barcode { position: absolute; left: {{ $pad }}mm; bottom: {{ $pad * 0.6 }}mm; width: {{ $barcodeW }}mm; }
    .barcode img { display: block; width: {{ $barcodeW }}mm; height: {{ $barcodeH }}mm; }
    .barcode div { font-size: {{ round(4.3 * $s, 1) }}pt; text-align: center; letter-spacing: 0.4pt; }
</style>
</head>
<body>
@foreach ($pages as $p => $positions)
    <div class="page{{ $loop->last ? ' last' : '' }}">
        @foreach ($positions as $pos)
            @php
                $label = $labels[$pos['index']];
                $hasBand = $options['highlight_offers'] && $options['show_offer_name'] && $label['offer'] !== null;
                $top = $pad + ($hasBand ? $band : 0);
                $foot = array_values(array_filter([
                    $options['show_pmp'] ? $label['pmp'] : null,
                    $options['show_drs'] ? $label['deposit'] : null,
                    $options['show_shop_name'] ? $label['shop'] : null,
                    $options['show_date'] ? $label['date'] : null,
                ]));
                $footH = $foot !== [] ? round(2.2 * $s, 2) : 0;
                $unitH = $options['show_unit_price'] && $label['unitPrice'] !== null ? round(2.6 * $s, 2) : 0;
            @endphp
            <div class="label" style="left: {{ $pos['x'] }}mm; top: {{ $pos['y'] }}mm;">
                @if ($hasBand)
                    <div class="band">{{ $label['offer'] }}</div>
                @endif
                <div class="name" style="top: {{ $top }}mm;">{{ $label['name'] }}</div>
                @if (! $hasBand && $options['show_offer_name'] && $label['offer'] !== null)
                    <div class="offer" style="top: {{ round($top + ($tiny ? 1 : 2) * 1.15 * 7.5 * $s * 0.3528 + 0.4, 2) }}mm;">{{ $label['offer'] }}@if ($label['offerUntil']) · {{ $label['offerUntil'] }}@endif</div>
                @endif
                <div class="price" style="bottom: {{ round($pad * 0.6 + $footH + $unitH, 2) }}mm;">{{ $label['priceText'] }}</div>
                @if ($unitH > 0)
                    <div class="unit" style="bottom: {{ round($pad * 0.6 + $footH, 2) }}mm;">{{ $label['unitPrice'] }}</div>
                @endif
                @if ($foot !== [])
                    <div class="foot">{{ implode(' · ', $foot) }}</div>
                @endif
                @if ($options['show_barcode'] && $label['barcode'] !== null && ! $tiny)
                    <div class="barcode">
                        <img src="data:image/svg+xml;base64,{{ base64_encode($label['barcode']['svg']) }}" alt="">
                        <div>{{ $label['barcode']['text'] }}</div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
