<x-mail::message>
# Your order is ready to collect

Hi {{ $firstName }}, your order {{ $data->reference }} from **{{ $data->businessName }}** is packed and waiting for you.

<x-mail::facts :rows="$facts" />

Give your order number at the counter. You pay at the shop when you collect.
@if ($hasWeighed)
Weighed items are priced on the scale, so the total may change a little from {{ $total }}.
@endif
@if ($data->orderUrl)

<x-mail::button :url="$data->orderUrl">
View your order
</x-mail::button>
@endif

{{ $data->businessName }}

<x-slot:subcopy>
Sent by Switch & Save on behalf of {{ $data->businessName }}. Can't make it? Reply to this email to let the shop know.
</x-slot:subcopy>
</x-mail::message>
