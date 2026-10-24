<x-mail::store-message :brand="$brand">
# Your order is cancelled

Hi {{ $firstName }}, we are sorry: **{{ $brand->name }}** has had to cancel your order {{ $data->reference }}.

@if ($data->cancelReason)
<x-mail::panel>
{{ $data->cancelReason }}
</x-mail::panel>
@endif

{{ $data->paid ? 'Your card payment will be refunded by '.$brand->name.'.' : 'You have not paid anything, so there is nothing to refund.' }} You are welcome to place a new order at any time.
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
