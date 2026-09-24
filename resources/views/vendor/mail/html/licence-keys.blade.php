@props(['tills' => []])
<table class="keys" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<th align="left">Till</th>
<th align="left">Licence key</th>
</tr>
@foreach ($tills as $till)
<tr>
<td><span class="till-name">{{ $till->tillName }}</span><br><span class="branch-name">{{ $till->branchName }}</span></td>
<td><span class="key">{{ $till->licenceKey }}</span></td>
</tr>
@endforeach
</table>
