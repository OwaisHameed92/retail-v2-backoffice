<x-mail::message>
# New online order {{ $data->reference }}

@if ($data->isDelivery())
**{{ $data->customerName }}** has placed a delivery order at {{ $data->businessName }}. {{ $data->paid ? 'It is paid by card.' : 'They pay the driver when it arrives.' }}
@else
**{{ $data->customerName }}** has placed a click & collect order at {{ $data->businessName }}. {{ $data->paid ? 'It is paid by card.' : 'They pay at the shop when they collect.' }}
@endif

<x-mail::facts :rows="$facts" />

@include('mail.partials.online-order-lines')

## What to do

@if ($data->isDelivery())
Pick and pack the order and ring it up on the till before it goes out, then mark it on its way on the online orders board: we email the customer. Deliver it in the time chosen.
@else
Pick and pack the order before the collection time, then mark it ready on the online orders board: we email the customer that it is ready. When they collect, ring it up on the till as a normal sale.
@endif
@if ($data->portalUrl)

<x-mail::button :url="$data->portalUrl">
Open online orders
</x-mail::button>
@endif

<x-slot:subcopy>
Sent by Switch & Save for {{ $data->businessName }}'s online store. Reply to this email to write to the customer.
</x-slot:subcopy>
</x-mail::message>
