<x-mail::message>
# New online order {{ $data->reference }}

**{{ $data->customerName }}** has placed a click & collect order at {{ $data->businessName }}. They pay at the shop when they collect.

<x-mail::facts :rows="$facts" />

@include('mail.partials.online-order-lines')

## What to do

Pick and pack the order before the collection time, then mark it ready on the online orders board: we email the customer that it is ready. When they collect, ring it up on the till as a normal sale.
@if ($data->portalUrl)

<x-mail::button :url="$data->portalUrl">
Open online orders
</x-mail::button>
@endif

<x-slot:subcopy>
Sent by Switch & Save for {{ $data->businessName }}'s online store. Reply to this email to write to the customer.
</x-slot:subcopy>
</x-mail::message>
