<x-mail::store-message :brand="$brand">
# Your order was delivered

Hi {{ $firstName }}, your order {{ $data->reference }} from **{{ $brand->name }}** was delivered. Thank you for shopping with us.

<x-mail::facts :rows="$facts" />

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
