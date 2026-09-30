<x-mail::message>
# {{ $data->kind }} requested

**{{ $data->requestedBy }}** from **{{ $data->businessName }}** asked for {{ $data->what }} from their portal.

<x-mail::facts :rows="$facts" />
@if ($data->message)

<x-mail::panel>
{{ $data->message }}
</x-mail::panel>
@endif

<x-mail::button :url="$tenantUrl">
Open the business
</x-mail::button>

They cannot add tills or shops themselves. Call them to agree the price, raise the tills allowed or add the shop, then mark the request resolved on the licence page.
</x-mail::message>
