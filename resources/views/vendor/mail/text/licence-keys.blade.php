@props(['tills' => []])
@foreach ($tills as $till)
{{ $till->branchName }} – {{ $till->tillName }}: {{ $till->licenceKey }}
@endforeach
