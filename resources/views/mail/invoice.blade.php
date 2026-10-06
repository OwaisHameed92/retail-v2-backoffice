<x-mail::message>
@if ($paid)
# Invoice {{ $data->invoiceNumber }} is paid

Hi {{ $firstName }}, here is a copy of invoice **{{ $data->invoiceNumber }}** for **{{ $data->businessName }}**. It is paid in full, thank you. There is nothing more to do.
@elseif ($overdue)
# Invoice {{ $data->invoiceNumber }} is overdue

Hi {{ $firstName }}, invoice **{{ $data->invoiceNumber }}** for **{{ $data->businessName }}** was due on {{ $dueOn }} and **{{ $amountDue }}** is still to pay.

<x-mail::notice>
Pay as soon as you can to keep trading. If it stays unpaid, your account is suspended and your tills stop taking sales at their next check-in.
</x-mail::notice>
@else
# Your invoice {{ $data->invoiceNumber }}

@if ($directDebitOn)
Hi {{ $firstName }}, {{ $data->resent ? 'here is your invoice again' : 'here is your Switch & Save invoice' }} for **{{ $data->businessName }}**. We collect **{{ $amountDue }}** by Direct Debit on {{ $directDebitOn }}.
@else
Hi {{ $firstName }}, {{ $data->resent ? 'here is your invoice again' : 'here is your Switch & Save invoice' }} for **{{ $data->businessName }}**. The amount due is **{{ $amountDue }}**, by {{ $dueOn }}.
@endif
@endif

<x-mail::facts :rows="$facts" />

The invoice is attached as a PDF.
@if (! $paid && $directDebitOn)

## How to pay

Nothing to do: we collect **{{ $amountDue }}** by Direct Debit on {{ $directDebitOn }}. Your licences are renewed as soon as the payment clears.
@endif
@unless ($paid || $directDebitOn)

## How to pay

@if ($data->howToPay)
{{ $data->howToPay }} Your licences are renewed as soon as the invoice is paid.
@else
We take cash, or you can pay by bank transfer. Use **{{ $data->invoiceNumber }}** as the reference so we can match your payment. Your licences are renewed as soon as the invoice is paid.
@endif
@if (count($data->bankDetails) > 0)

<x-mail::panel>
@if ($data->howToPay)
**Pay to**<br>
@else
**Bank transfer**<br>
@endif
@foreach ($data->bankDetails as $line)
{{ $line }}<br>
@endforeach
</x-mail::panel>
@endif
@endunless

<x-mail::button :url="$portalUrl">
View your account
</x-mail::button>

Questions about this invoice? Just reply to this email.

The Switch & Save team
</x-mail::message>
