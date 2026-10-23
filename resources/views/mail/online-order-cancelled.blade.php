<x-mail::message>
# Your order is cancelled

Hi {{ $firstName }}, we are sorry: **{{ $data->businessName }}** has had to cancel your order {{ $data->reference }}.

@if ($data->cancelReason)
<x-mail::panel>
{{ $data->cancelReason }}
</x-mail::panel>
@endif

You have not paid anything, so there is nothing to refund. You are welcome to place a new order at any time.
@if ($data->orderUrl)

<x-mail::button :url="$data->orderUrl">
View your order
</x-mail::button>
@endif

{{ $data->businessName }}

<x-slot:subcopy>
Sent by Switch & Save on behalf of {{ $data->businessName }}. Questions? Reply to this email to reach the shop.
</x-slot:subcopy>
</x-mail::message>
