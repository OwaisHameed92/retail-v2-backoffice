<x-mail::message>
# Join {{ $data->businessName }}

Hi {{ $firstName }}, {{ $data->inviterName ?? 'the owner' }} has invited you to the **{{ $data->businessName }}** portal on Switch & Save as **{{ $data->roleLabel }}**@if ($data->branchName) for the **{{ $data->branchName }}** shop @endif.

The portal shows your sales, products and reports from anywhere.

<x-mail::button :url="$data->url">
Accept invitation
</x-mail::button>

This link is for {{ $data->email }} only and works until {{ $expiresOn }}. If it has expired, ask {{ $data->inviterName ?? 'the owner' }} to send a new one.

If you were not expecting this invitation, you can ignore this email.

The Switch & Save team

<x-slot:subcopy>
If the button does not work, copy this link into your browser: <span class="break-all">[{{ $data->url }}]({{ $data->url }})</span>
</x-slot:subcopy>
</x-mail::message>
