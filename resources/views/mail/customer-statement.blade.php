<x-mail::message>
# Your statement from {{ $data->businessName }}

Hi {{ $firstName }}, here is your account statement from **{{ $data->businessName }}** for {{ $data->period }}.

<x-mail::facts :rows="$facts" />

The full statement, with every purchase and payment across our shops, is attached as a PDF.
@if ($owes)

Please pay the amount owed at any of our tills. If you have already paid, thank you: payments made after the statement date show on your next one.
@endif

Questions about your account? Contact {{ $data->businessName }}@if ($data->businessPhone) on {{ $data->businessPhone }}@endif @if ($data->businessEmail) or at {{ $data->businessEmail }}@endif.

{{ $data->businessName }}

<x-slot:subcopy>
Sent by Switch & Save on behalf of {{ $data->businessName }}. This is a statement of your account, not a marketing email.
</x-slot:subcopy>
</x-mail::message>
