<x-mail::message>
@if ($data->firstTime)
# Set your password

@if ($data->businessName)
Hi {{ $firstName }}, your Switch & Save portal account for **{{ $data->businessName }}** is ready. Choose a password to sign in for the first time.
@else
Hi {{ $firstName }}, your Switch & Save portal account is ready. Choose a password to sign in for the first time.
@endif
@else
# Reset your password

Hi {{ $firstName }}, we received a request to reset the password for your Switch & Save account.
@endif

<x-mail::button :url="$data->url">
{{ $data->firstTime ? 'Set your password' : 'Reset your password' }}
</x-mail::button>

This link works once and expires in {{ $expiresIn }}.
@if ($data->firstTime)
If it has expired, use "Forgot password" on the [sign-in page]({{ $loginUrl }}) to get a new one.
@endif

@unless ($data->firstTime)
If you did not ask to reset your password, you can ignore this email. Your password will not change.
@endunless

The Switch & Save team

<x-slot:subcopy>
If the button does not work, copy this link into your browser: <span class="break-all">[{{ $data->url }}]({{ $data->url }})</span>
</x-slot:subcopy>
</x-mail::message>
