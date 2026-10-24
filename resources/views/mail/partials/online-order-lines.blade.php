<x-mail::table>
| Item | Qty | Price | Total |
|:-----|:----|------:|------:|
@foreach ($lines as $line)
| {{ $line['name'] }} | {{ $line['quantity'] }} | {{ $line['price'] }} | {{ $line['total'] }} |
@endforeach
@if (! empty($deliveryFee))
| Items | | | {{ $total }} |
| Delivery | | | {{ $deliveryFee }} |
| **Total** | | | **{{ $grandTotal }}** |
@else
| **Total** | | | **{{ $total }}** |
@endif
</x-mail::table>
@if ($hasWeighed)

Weighed items are priced on the scale at the shop, so the total may change a little.
@endif
