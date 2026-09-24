<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('sspos.portal_url')">
Switch &amp; Save
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
<span class="tagline">Switch &amp; Save</span> · Smart Solutions for Smart Businesses

Need help? Email [{{ config('sspos.support_email') }}](mailto:{{ config('sspos.support_email') }})@if (filled(config('sspos.support_phone'))) or call {{ config('sspos.support_phone') }}@endif.

© {{ date('Y') }} Switch &amp; Save. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
