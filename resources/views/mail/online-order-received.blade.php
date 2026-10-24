<x-mail::store-message :brand="$brand">
# We've got your order

Hi {{ $firstName }}, thank you for your order with **{{ $brand->name }}**. We will email you again when it is ready to collect.

<x-mail::facts :rows="$facts" />

@include('mail.partials.online-order-lines')

Give your order number, {{ $data->reference }}, at the counter. You pay at the shop when you collect.
@if ($data->orderUrl)

<x-mail::button :url="$data->orderUrl">
View your order
</x-mail::button>
@endif

{{ $brand->name }}

<x-slot:subcopy>
Questions about your order? Reply to this email to reach {{ $brand->name }}.
</x-slot:subcopy>
</x-mail::store-message>
