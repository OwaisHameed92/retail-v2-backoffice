<x-mail::message>
# {{ $data->title }}

Hi {{ $firstName }}, our checks found something at **{{ $data->shopName }}** that is far from its normal.

{{ \App\Domain\Mail\Support\MailFormat::plain($data->summary) }}

<x-mail::facts :rows="$facts" />

## What to do

Open it in your portal to see the figures, the usual ones and links to the sales, shifts or staff behind it. Mark it acknowledged while you look into it, or dismiss it with a reason if there is a good explanation. An unusual figure is worth a look, not proof of wrongdoing.

<x-mail::button :url="$data->url">
Open it in your portal
</x-mail::button>

The Switch & Save team

<x-slot:subcopy>
You get this email because you chose to hear about "Unusual activity" straight away. [Stop these emails]({{ $data->unsubscribeUrl }}) or [choose your alerts]({{ $data->settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
