<x-mail::message>
# Your licences are renewed

Hi {{ $firstName }}, thank you for your payment. We have renewed {{ $tillCount }} for **{{ $data->businessName }}**.

<x-mail::facts :rows="$facts" />

<x-mail::table>
| Branch | Till | Valid until |
|:-------|:-----|:------------|
@foreach ($tills as $till)
| {{ $till['branch'] }} | {{ $till['till'] }} | {{ $till['expires'] }} |
@endforeach
</x-mail::table>

There is nothing to do on the tills. They pick up the new expiry date at their next check-in.

<x-mail::button :url="$portalUrl">
View your account
</x-mail::button>

The Switch & Save team
</x-mail::message>
