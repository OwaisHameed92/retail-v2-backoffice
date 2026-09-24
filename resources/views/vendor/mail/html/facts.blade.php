@props(['rows' => []])
<table class="facts" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $label => $value)
<tr>
<td class="fact-label">{{ $label }}</td>
<td class="fact-value">{{ $value }}</td>
</tr>
@endforeach
</table>
