<x-mail::store-message :brand="$brand">
# Your order is on its way

Hi {{ $firstName }}, your order {{ $data->reference }} from **{{ $brand->name }}** has left the shop and is out for delivery.

<x-mail::facts :rows="$facts" />

@if ($data->paid)
It is paid: there is nothing to pay the driver.
@else
Please have {{ $grandTotal }} ready to pay the driver by card or cash.
@endif
@if ($data->orderUrl)

<x-mail::button :url="$data->orderUrl">
View your order
</x-mail::button>
@endif

{{ $brand->name }}

<x-slot:subcopy>
Questions? Reply to this email to reach {{ $brand->name }}.
</x-slot:subcopy>
</x-mail::store-message>
