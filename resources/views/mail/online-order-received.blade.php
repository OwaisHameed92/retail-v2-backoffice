<x-mail::store-message :brand="$brand">
# We've got your order

Hi {{ $firstName }}, thank you for your order with **{{ $brand->name }}**. We will email you again when it is {{ $data->isDelivery() ? 'on its way' : 'ready to collect' }}.

<x-mail::facts :rows="$facts" />

@include('mail.partials.online-order-lines')

@if ($data->isDelivery())
We deliver to {{ $data->deliveryAddress }}. {{ $data->paid ? 'It is paid by card.' : 'You pay the driver when it arrives.' }}
@else
Give your order number, {{ $data->reference }}, at the counter. {{ $data->paid ? 'It is paid by card.' : 'You pay at the shop when you collect.' }}
@endif
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
