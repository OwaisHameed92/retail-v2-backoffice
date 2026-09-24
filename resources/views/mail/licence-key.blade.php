<x-mail::message>
# {{ $single ? 'Your licence key' : 'Your licence keys' }}

Hi {{ $firstName }},
@if ($data->replacesOldKey)
we have replaced the licence {{ $single ? 'key' : 'keys' }} for {{ $tillCount }} at **{{ $data->businessName }}**. The old {{ $single ? 'key no longer works' : 'keys no longer work' }}.
@else
here {{ $single ? 'is the licence key' : 'are the licence keys' }} for {{ $tillCount }} at **{{ $data->businessName }}**.
@endif

<x-mail::licence-keys :tills="$tills" />

<x-mail::notice>
**Keep {{ $single ? 'this key' : 'these keys' }} safe.** Treat {{ $single ? 'it' : 'them' }} like a password: store {{ $single ? 'it' : 'them' }} somewhere secure and do not share {{ $single ? 'it' : 'them' }} outside your business.
</x-mail::notice>

## How to activate a till

1. On the till PC, open SSPOS. If it is not installed yet, download it from [{{ preg_replace('#^https?://#', '', $downloadUrl) }}]({{ $downloadUrl }}).
2. Enter the licence key for that till when SSPOS asks for it.
3. The till connects to your account and is ready to trade.

If you did not expect this email, contact us straight away by replying to it.

The Switch & Save team
</x-mail::message>
