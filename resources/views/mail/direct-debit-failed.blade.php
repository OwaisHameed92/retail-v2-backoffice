<x-mail::message>
@if ($data->reminder)
# Your payment is still unpaid

Hi {{ $firstName }}, the Direct Debit payment of **{{ $amount }}** for **{{ $data->businessName }}** did not go through and it is still unpaid.
@elseif ($data->chargedBack)
# Your Direct Debit payment was reversed

Hi {{ $firstName }}, the Direct Debit payment of **{{ $amount }}** for **{{ $data->businessName }}** was reversed by your bank, so the invoice is unpaid again.
@else
# Your Direct Debit payment failed

Hi {{ $firstName }}, we could not collect **{{ $amount }}** by Direct Debit for **{{ $data->businessName }}**.
@endif

<x-mail::facts :rows="$facts" />

<x-mail::notice>
If it is still unpaid {{ $suspendAfter }} days after the due date (after the reversal, for a payment your bank reversed), your account is suspended and your tills stop taking sales at their next check-in. Paying it unlocks them straight away.
</x-mail::notice>

## What to do

Make sure there is enough money in the account; GoCardless may try the payment again automatically. You can also pay by bank transfer now, using the invoice number as the reference.
@if (count($data->bankDetails) > 0)

<x-mail::panel>
**Bank transfer**<br>
@foreach ($data->bankDetails as $line)
{{ $line }}<br>
@endforeach
</x-mail::panel>
@endif

Questions? Just reply to this email.

The Switch & Save team
</x-mail::message>
