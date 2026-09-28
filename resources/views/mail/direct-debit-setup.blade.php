<x-mail::message>
# Set up your Direct Debit

Hi {{ $firstName }}, **{{ $data->businessName }}** pays Switch & Save by Direct Debit. Please set it up using the secure GoCardless page below. It takes about two minutes and you only need your bank details.
@if (count($facts) > 0)

<x-mail::facts :rows="$facts" />
@endif

<x-mail::button :url="$data->setupUrl">
Set up Direct Debit
</x-mail::button>

## Good to know

- GoCardless emails you before every collection, and your payments are protected by the Direct Debit Guarantee.
- We email you an invoice for every payment.
- The link works for {{ $linkDays }} days. Reply to this email if you need a new one.

The Switch & Save team
</x-mail::message>
