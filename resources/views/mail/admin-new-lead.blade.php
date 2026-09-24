<x-mail::message>
# New trial request

**{{ $data->contactName }}** from **{{ $data->businessName }}** has asked for a free trial.

<x-mail::facts :rows="$facts" />
@if ($data->message)

<x-mail::panel>
{{ $data->message }}
</x-mail::panel>
@endif

<x-mail::button :url="$leadsUrl">
Open leads
</x-mail::button>

Aim to get back to them within one working day.
</x-mail::message>
