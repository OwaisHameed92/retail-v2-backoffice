<x-mail::message>
# {{ $data->headline() }}

@if ($data->problem === 'tillOffline')
Hi {{ $firstName }}, good news: **{{ $data->tillName ?? 'the till' }}** at **{{ $data->shopName }}** is in touch with the portal again. Anything it took while offline is on its way to your dashboard.
@else
Hi {{ $firstName }}, good news: sync at **{{ $data->shopName }}** is working again, so its sales and stock are reaching your dashboard.
@endif

<x-mail::facts :rows="$facts" />

There is nothing more to do.

<x-mail::button :url="$data->url">
Open the shop in your portal
</x-mail::button>

The Switch & Save team

<x-slot:subcopy>
You get this email because you chose to hear about "{{ $data->type === 'tillOffline' ? 'Till offline' : 'Sync failing or stalled' }}" straight away. [Stop these emails]({{ $data->unsubscribeUrl }}) or [choose your alerts]({{ $data->settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
