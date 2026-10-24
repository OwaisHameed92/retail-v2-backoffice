@props(['brand'])
{{ $brand->name }}

{!! strip_tags($slot) !!}
@isset($subcopy)

{!! strip_tags($subcopy) !!}
@endisset

--
{{ $brand->name }}
@if ($brand->address)
{{ $brand->address }}
@endif
@if ($brand->phone)
{{ $brand->phone }}
@endif
@if ($brand->storeUrl)
{{ $brand->storeUrl }}
@endif

Online store by Switch & Save
