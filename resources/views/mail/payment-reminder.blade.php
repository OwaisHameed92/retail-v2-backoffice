<x-mail::message>
@if ($data->kind === 'overdue')
# Invoice {{ $data->invoiceNumber }} is overdue

Hi {{ $firstName }}, invoice **{{ $data->invoiceNumber }}** for **{{ $data->businessName }}** was due on {{ $dueOn }} and **{{ $amountDue }}** is still to pay.

<x-mail::notice>
@if ($locksOn)
Please pay it now to keep trading. If it is still unpaid on {{ $locksOn }}, your account is suspended and your tills stop taking sales at their next check-in.
@else
Please pay it now to keep trading.
@endif
</x-mail::notice>
@elseif ($data->kind === 'dueToday')
# Invoice {{ $data->invoiceNumber }} is due today

Hi {{ $firstName }}, a reminder that invoice **{{ $data->invoiceNumber }}** for **{{ $data->businessName }}** is due today. The amount due is **{{ $amountDue }}**.
@else
# Invoice {{ $data->invoiceNumber }} is due on {{ $dueOn }}

Hi {{ $firstName }}, a friendly reminder that invoice **{{ $data->invoiceNumber }}** for **{{ $data->businessName }}** is due on {{ $dueOn }}. The amount due is **{{ $amountDue }}**.
@endif

<x-mail::facts :rows="$facts" />

## How to pay

{{ $data->howToPay }} Your licences are renewed as soon as the payment is recorded.
@if (count($data->payLines) > 0)

<x-mail::panel>
@foreach ($data->payLines as $line)
{{ $line }}<br>
@endforeach
</x-mail::panel>
@endif

<x-mail::button :url="$portalUrl">
View your invoices
</x-mail::button>

Already paid? Thank you, there is nothing more to do: it can take a working day for us to record it. Questions? Just reply to this email.

The Switch & Save team
</x-mail::message>
