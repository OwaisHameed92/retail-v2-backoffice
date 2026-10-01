<x-mail::message>
# Your daily summary

Hi {{ $firstName }}, here is what needs a look at **{{ $data->businessName }}** this morning, {{ $date }}.
@foreach ($data->sections as $section)

## {{ $section['title'] }}

{{ $section['summary'] }}

<x-mail::panel>
@foreach ($section['items'] as $item)
{{ \App\Domain\Mail\Support\MailFormat::plain($item) }}<br>
@endforeach
@if ($section['more'] > 0)
and {{ $section['more'] }} more<br>
@endif
</x-mail::panel>

[Open in your portal]({{ $section['url'] }}) · [Stop these in the digest]({{ $section['unsubscribeUrl'] }})
@endforeach

The Switch & Save team

<x-slot:subcopy>
You get this summary at 7am when something needs a look. [Stop the daily summary]({{ $data->unsubscribeUrl }}) or [choose your alerts]({{ $data->settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
