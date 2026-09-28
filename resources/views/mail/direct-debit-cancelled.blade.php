<x-mail::message>
# Your Direct Debit has stopped

Hi {{ $firstName }}, the Direct Debit that pays Switch & Save for **{{ $data->businessName }}** has stopped (status: {{ $data->status }}). We cannot collect your next payment.

<x-mail::notice>
Please set up a new Direct Debit by {{ $graceUntil }}. After that the account is overdue, and unpaid invoices lead to suspension.
</x-mail::notice>
@if ($data->setupUrl)

<x-mail::button :url="$data->setupUrl">
Set up a new Direct Debit
</x-mail::button>
@endif

If you cancelled it by mistake or want to pay another way, just reply to this email.

The Switch & Save team
</x-mail::message>
