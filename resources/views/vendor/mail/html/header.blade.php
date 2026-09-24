@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;" target="_blank" rel="noopener">
<img src="{{ rtrim((string) config('app.url'), '/') }}/images/brand/switch-save-logo.png" class="logo" width="180" height="36" alt="Switch &amp; Save">
</a>
</td>
</tr>
