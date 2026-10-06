<x-mail::message>
# Your free trial has ended

Hi {{ $firstName }}, the free trial for **{{ $data->businessName }}** ended on {{ $endedOn }}.

<x-mail::notice>
Your {{ $tillCount }} will stop taking sales at their next check-in until the account is paid.
</x-mail::notice>

## Nothing is lost

Your products, sales history and settings are kept safe. As soon as you pay, your tills unlock and carry on where they left off.

## How to carry on

@if ($data->directDebitUrl)
Set up your Direct Debit now to keep trading. Without it, the account is suspended a few days after the trial.

<x-mail::button :url="$data->directDebitUrl">
Set up Direct Debit
</x-mail::button>
@elseif ($data->howToPay)
{{ $data->howToPay }}
@else
We take payment in cash for now. Reply to this email or call us and we will arrange it with you, usually the same day.
@endif
@if ($data->priceSummary)

<x-mail::panel>
**Your price:** {{ $data->priceSummary }}, for {{ $tillCount }}.
</x-mail::panel>
@endif

<x-mail::button :url="$portalUrl">
Sign in to your portal
</x-mail::button>

Thanks for trying Switch & Save.

The Switch & Save team
</x-mail::message>
