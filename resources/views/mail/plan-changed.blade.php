<x-mail::message>
# Your plan has changed

Hi {{ $firstName }}, we have moved **{{ $data->businessName }}** to the **{{ $data->toPlan }}** plan.

<x-mail::facts :rows="$facts" />

## What changes

@foreach ($data->changes as $change)
- {{ $change }}
@endforeach
@if ($data->directDebitUrl)

<x-mail::notice>
Please set up your Direct Debit by **{{ $directDebitBy }}** to keep your tills trading.
</x-mail::notice>

<x-mail::button :url="$data->directDebitUrl">
Set up Direct Debit
</x-mail::button>
@elseif ($data->howToPay)

{{ $data->howToPay }}
@endif

There is nothing to do on the tills. They pick up the change at their next check-in.

<x-mail::button :url="$portalUrl">
View your account
</x-mail::button>

If anything here looks wrong, reply to this email and we will put it right.

The Switch & Save team
</x-mail::message>
