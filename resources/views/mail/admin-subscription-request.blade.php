<x-mail::message>
# {{ $data->kind }} requested

**{{ $data->requestedBy }}** from **{{ $data->businessName }}** asked {{ $data->what }} from their portal.

<x-mail::facts :rows="$facts" />
@if ($data->message)

<x-mail::panel>
{{ $data->message }}
</x-mail::panel>
@endif

<x-mail::button :url="$tenantUrl">
Open the business
</x-mail::button>

@if ($manualCollection)
Nothing has changed yet: the business cannot cancel its tills itself. Call them, make the change on the tenant's Billing tab, then mark the request resolved on the licence page.
@else
Nothing has changed yet: the business cannot cancel its tills or move its Direct Debit itself. Call them, make the change on the tenant's Billing tab, then mark the request resolved on the licence page.
@endif
</x-mail::message>
