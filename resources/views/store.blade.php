<!DOCTYPE html>
{{-- The public online store's page (OS-2, OS-5): the shop's own brand and template (fonts and colours, resources/css/store.css), light only, no portal scripts or route list. --}}
@php($template = ($page['props']['store']['template'] ?? null) === 'butcher' ? 'butcher' : 'freshMarket')
<html lang="en-GB" data-template="{{ $template }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ $page['props']['store']['name'] ?? 'Online store' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        @if ($template === 'butcher')
            <link href="https://fonts.bunny.net/css?family=fraunces:600,600i,700,800|inter:400,500,600,700&display=swap" rel="stylesheet" />
        @else
            <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|inter:400,500,600,700&display=swap" rel="stylesheet" />
        @endif
        @if (! empty($page['props']['store']['logoUrl']))
            <link rel="icon" href="{{ $page['props']['store']['logoUrl'] }}">
        @endif
        <meta name="theme-color" content="{{ $template === 'butcher' ? '#F7F3EC' : '#F4F3EE' }}">
        <meta name="robots" content="index, follow">

        @viteReactRefresh
        @vite(['resources/js/store.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="bg-paper font-sans text-ink antialiased">
        @inertia
    </body>
</html>
