{{--
    OS-10: a shop's own email to its customer (online store). The shop's logo and name on its brand colour, the body,
    then the shop's name, address and phone in the footer. Only a small "Online store by Switch & Save" line at the
    very bottom. Colours come from StoreMailBrand (inline, as email clients need); the theme `mail.themes.online-store`
    colours links, buttons and panels.
--}}
@props(['brand'])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $brand->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body, .footer, .store-bar {
width: 100% !important;
}

.content-cell {
padding: 24px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<!-- The shop's header -->
<tr>
<td class="header" style="padding: 32px 0 16px;">
<table class="store-bar" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" bgcolor="{{ $brand->colour }}" style="background-color: {{ $brand->colour }}; border-radius: 12px; margin: 0 auto; width: 570px;">
<tr>
<td align="center" style="padding: 20px 28px; text-align: center;">
@if ($brand->logoUrl)
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto 10px;">
<tr>
<td bgcolor="#FFFFFF" style="background-color: #FFFFFF; border-radius: 8px; padding: 6px 10px;">
<img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="48" style="border: 0; display: block; height: 48px; max-height: 48px; max-width: 200px; width: auto;">
</td>
</tr>
</table>
@endif
@if ($brand->storeUrl)
<a href="{{ $brand->storeUrl }}" target="_blank" rel="noopener" style="color: {{ $brand->onColour }}; font-size: 20px; font-weight: bold; text-decoration: none;">{{ $brand->name }}</a>
@else
<span style="color: {{ $brand->onColour }}; font-size: 20px; font-weight: bold;">{{ $brand->name }}</span>
@endif
</td>
</tr>
</table>
</td>
</tr>

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

@isset($subcopy)
<table class="subcopy" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
{!! Illuminate\Mail\Markdown::parse($subcopy) !!}
</td>
</tr>
</table>
@endisset
</td>
</tr>
</table>
</td>
</tr>

<!-- The shop's footer -->
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center" style="padding: 24px 32px 32px;">
<p style="color: #374151; font-size: 13px; font-weight: bold; margin: 0 0 4px; text-align: center;">{{ $brand->name }}</p>
@if ($brand->address)
<p style="color: #6b7280; font-size: 12px; margin: 0 0 4px; text-align: center;">{{ $brand->address }}</p>
@endif
@if ($brand->phone)
<p style="color: #6b7280; font-size: 12px; margin: 0 0 4px; text-align: center;">{{ $brand->phone }}</p>
@endif
@if ($brand->storeUrl)
<p style="font-size: 12px; margin: 0 0 4px; text-align: center;"><a href="{{ $brand->storeUrl }}" target="_blank" rel="noopener" style="color: {{ $brand->linkColour }};">{{ preg_replace('#^https?://#', '', $brand->storeUrl) }}</a></p>
@endif
<p style="color: #9ca3af; font-size: 11px; margin: 16px 0 0; text-align: center;">Online store by Switch &amp; Save</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
