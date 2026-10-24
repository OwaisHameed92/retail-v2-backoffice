<x-mail::store-message :brand="$brand">
# Your order is ready to collect

Hi {{ $firstName }}, your order {{ $data->reference }} from **{{ $brand->name }}** is packed and waiting for you.

<x-mail::facts :rows="$facts" />

Give your order number at the counter. {{ $data->paid ? 'It is paid by card.' : 'You pay at the shop when you collect.' }}
@if ($hasWeighed)
Weighed items are priced on the scale, so the total may change a little from {{ $total }}.
@endif
@if ($data->orderUrl)

<x-mail::button :url="$data->orderUrl">
View your order
</x-mail::button>
@endif

{{ $brand->name }}

<x-slot:subcopy>
Can't make it? Reply to this email to let {{ $brand->name }} know.
</x-slot:subcopy>
</x-mail::store-message>
