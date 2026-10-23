<!DOCTYPE html>
{{-- The public online store's page (OS-2): the shop's own brand, light only, no portal scripts or route list. --}}
<html lang="en-GB">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ $page['props']['store']['name'] ?? 'Online store' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|inter:400,500,600,700&display=swap" rel="stylesheet" />
        @if (! empty($page['props']['store']['logoUrl']))
            <link rel="icon" href="{{ $page['props']['store']['logoUrl'] }}">
        @endif
        <meta name="theme-color" content="#F4F3EE">
        <meta name="robots" content="index, follow">

        @viteReactRefresh
        @vite(['resources/js/store.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="bg-paper font-sans text-ink antialiased">
        @inertia
    </body>
</html>
