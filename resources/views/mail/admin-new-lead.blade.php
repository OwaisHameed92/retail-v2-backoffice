{{-- Visitor-typed text is Markdown-escaped (MailFormat::plain, security review L3): it renders literally, never as a link. --}}
<x-mail::message>
# New trial request

**{{ \App\Domain\Mail\Support\MailFormat::plain($data->contactName) }}** from **{{ \App\Domain\Mail\Support\MailFormat::plain($data->businessName) }}** has asked for a free trial.

<x-mail::facts :rows="$facts" />
@if ($data->possibleDuplicate)

<x-mail::notice>
**Possible duplicate:** {{ \App\Domain\Mail\Support\MailFormat::plain($data->possibleDuplicate) }}. Check before contacting them.
</x-mail::notice>
@endif
@if ($data->message)

<x-mail::panel>
{{ \App\Domain\Mail\Support\MailFormat::plain($data->message) }}
</x-mail::panel>
@endif

<x-mail::button :url="$leadUrl">
Open the lead
</x-mail::button>

Aim to get back to them within one working day.
</x-mail::message>
