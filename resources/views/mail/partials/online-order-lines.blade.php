<x-mail::table>
| Item | Qty | Price | Total |
|:-----|:----|------:|------:|
@foreach ($lines as $line)
| {{ $line['name'] }} | {{ $line['quantity'] }} | {{ $line['price'] }} | {{ $line['total'] }} |
@endforeach
| **Total** | | | **{{ $total }}** |
</x-mail::table>
@if ($hasWeighed)

Weighed items are priced on the scale when the order is collected, so the total may change a little.
@endif
