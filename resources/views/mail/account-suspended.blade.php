<x-mail::message>
# Your account is suspended

Hi {{ $firstName }}, we have suspended the Switch & Save account for **{{ $data->businessName }}** from {{ $suspendedOn }}.

<x-mail::notice>
**Reason:** {{ $data->reason }}
</x-mail::notice>

## What this means

Your tills stop taking sales at their next check-in. Your products, sales history and settings are kept safe.

## How to fix it

@if ($data->howToFix)
{{ $data->howToFix }}
@else
@if ($amountDue)
Pay the amount due of **{{ $amountDue }}** in cash, or contact us if you think this is a mistake. We will reactivate your account as soon as it is sorted, usually the same day.
@else
Pay the amount due in cash, or contact us if you think this is a mistake. We will reactivate your account as soon as it is sorted, usually the same day.
@endif
@endif

@if ($supportPhone)
Email [{{ $supportEmail }}](mailto:{{ $supportEmail }}), call **{{ $supportPhone }}**, or simply reply to this email.
@else
Email [{{ $supportEmail }}](mailto:{{ $supportEmail }}) or simply reply to this email.
@endif

The Switch & Save team
</x-mail::message>
