<x-mail::message>
# Your free trial {{ $endsIn }}

Hi {{ $firstName }}, the free trial for **{{ $data->businessName }}** {{ $endsIn }}, on {{ $endsOn }}. We hope SSPOS has been working well for you.

## What happens next

To keep trading without a break, pay for your {{ $tillCount }} before the trial ends. If the trial ends unpaid, your tills stop taking sales at their next check-in. Your products, sales and settings stay safe, and everything carries on as soon as you pay.

## How to pay

@if ($data->directDebitUrl)
You pay by Direct Debit. Set it up now (it takes two minutes) so your tills carry on after the trial.

<x-mail::button :url="$data->directDebitUrl">
Set up Direct Debit
</x-mail::button>
@else
We take payment in cash for now. Reply to this email or call us and we will arrange it with you. Your licences are renewed the same day.
@endif
@if ($data->priceSummary)

<x-mail::panel>
**Your price:** {{ $data->priceSummary }}, for {{ $tillCount }}.
</x-mail::panel>
@endif

<x-mail::button :url="$portalUrl">
Sign in to your portal
</x-mail::button>

Questions about your trial? Just reply to this email.

The Switch & Save team
</x-mail::message>
