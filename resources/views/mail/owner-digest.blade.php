<x-mail::message>
@if ($summary)
# Your morning summary

Hi {{ $firstName }}, here is how **{{ $data->businessName }}** traded yesterday. {{ \App\Domain\Mail\Support\MailFormat::plain($summary['intro']) }}
@if ($summary['narrative'])

{{ \App\Domain\Mail\Support\MailFormat::plain($summary['narrative']) }}
@endif

<x-mail::table>
| Shop | {{ \App\Domain\Shared\Country\Country::tax('Sales (inc VAT)') }} | vs last week | vs last year |
| :--- | ---: | ---: | ---: |
@foreach ($summary['rows'] as $row)
| {!! $row['total'] ? '**' : '' !!}{{ \App\Domain\Mail\Support\MailFormat::plain($row['name']) }}{!! $row['total'] ? '**' : '' !!} | {{ $row['sales'] }} | {{ $row['week'] }} | {{ $row['year'] }} |
@endforeach
</x-mail::table>
@if ($summary['up'] !== [] || $summary['down'] !== [])

## Top movers against last week

@foreach ($summary['up'] as $line)
- Up: {{ \App\Domain\Mail\Support\MailFormat::plain($line) }}
@endforeach
@foreach ($summary['down'] as $line)
- Down: {{ \App\Domain\Mail\Support\MailFormat::plain($line) }}
@endforeach
@endif
@if ($summary['watch'] !== [])

## Worth a look

@foreach ($summary['watch'] as $line)
- {{ \App\Domain\Mail\Support\MailFormat::plain($line) }}
@endforeach
@endif

[Open your dashboard]({{ $summary['url'] }}) · [Stop the morning summary]({{ $summary['unsubscribeUrl'] }})
@if ($summary['narrative'])

<small>The paragraph at the top is written by AI from these figures; every figure comes straight from your tills' data.</small>
@endif
@else
# Your daily summary

Hi {{ $firstName }}, here is what needs a look at **{{ $data->businessName }}** this morning, {{ $date }}.
@endif
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
