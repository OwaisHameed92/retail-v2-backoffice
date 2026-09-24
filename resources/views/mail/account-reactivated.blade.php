<x-mail::message>
# Your account is active again

Hi {{ $firstName }}, good news: the Switch & Save account for **{{ $data->businessName }}** is active again.

Your {{ $tillCount }} can take sales again after their next check-in. If a till still shows as locked, restart SSPOS on that till.
@if ($activeUntil)

<x-mail::panel>
Your licences are valid until **{{ $activeUntil }}**.
</x-mail::panel>
@endif

<x-mail::button :url="$portalUrl">
Sign in to your portal
</x-mail::button>

Thanks for sorting this out with us.

The Switch & Save team
</x-mail::message>
