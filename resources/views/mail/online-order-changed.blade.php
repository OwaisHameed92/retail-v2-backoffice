<x-mail::store-message :brand="$brand">
# We changed your order

Hi {{ $firstName }}, while picking your order {{ $data->reference }}, **{{ $brand->name }}** had to change it. Here is what you will get now:

@include('mail.partials.online-order-lines')

@if ($data->paid)
You paid by card online: the difference is refunded to your card by {{ $brand->name }}.
@endif
If this does not suit you, reply to this email or call the shop before your {{ $data->isDelivery() ? 'delivery' : 'collection' }} time.
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
