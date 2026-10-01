<x-mail::message>
# {{ $data->headline() }}

@if ($data->problem === 'tillOffline')
Hi {{ $firstName }}, **{{ $data->tillName ?? 'a till' }}** at **{{ $data->shopName }}** has not been in touch with the portal for a few hours while the shop is open.
@elseif ($data->problem === 'syncStalled')
Hi {{ $firstName }}, the main till at **{{ $data->shopName }}** is on, but its sales and stock have stopped reaching the portal.
@else
Hi {{ $firstName }}, the portal keeps refusing what the main till at **{{ $data->shopName }}** sends, so its sales and stock are not reaching your dashboard.
@endif

<x-mail::facts :rows="$facts" />

## What to do

@if ($data->problem === 'tillOffline')
Check the till PC is switched on and connected to the internet. The till keeps trading offline and sends everything once it is back, so no sales are lost.
@else
Check the till is online and that cloud sync is switched on in the till's settings. If it carries on, call us and we will look at it with you. The till keeps every sale until sync works again.
@endif

<x-mail::button :url="$data->url">
Open the shop in your portal
</x-mail::button>

We email you again when it is fixed.

The Switch & Save team

<x-slot:subcopy>
You get this email because you chose to hear about "{{ $data->type === 'tillOffline' ? 'Till offline' : 'Sync failing or stalled' }}" straight away. [Stop these emails]({{ $data->unsubscribeUrl }}) or [choose your alerts]({{ $data->settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
