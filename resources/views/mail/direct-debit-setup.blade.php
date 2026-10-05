<x-mail::message>
# {{ $data->reminder ? 'Reminder: set up your Direct Debit' : 'Set up your Direct Debit' }}

Hi {{ $firstName }}, **{{ $data->businessName }}** pays Switch & Save by Direct Debit. Please set it up using the secure GoCardless page below. It takes about two minutes and you only need your bank details.
@if (count($facts) > 0)

<x-mail::facts :rows="$facts" />
@endif

@if ($deadline)

<x-mail::notice>
Please set it up by {{ $deadline }}. If it is not set up by then, your tills stop taking sales at their next check-in until it is.
</x-mail::notice>
@endif

<x-mail::button :url="$data->setupUrl">
Set up Direct Debit
</x-mail::button>

## Good to know

- The Direct Debit collects your monthly or yearly fee only. The setup fee is never taken by Direct Debit.
- GoCardless emails you before every collection, and your payments are protected by the Direct Debit Guarantee.
- We email you an invoice for every payment.
- The link works for {{ $linkDays }} days. Reply to this email if you need a new one.

The Switch & Save team
</x-mail::message>
