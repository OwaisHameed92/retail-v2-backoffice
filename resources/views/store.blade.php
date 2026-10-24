<!DOCTYPE html>
{{--
    The public online store's page (OS-2, OS-5, OS-8): the shop's own brand and template (fonts and colours,
    resources/css/store.css), light only, no portal scripts or route list. The head is written on the server
    (StoreMeta: title, description, Open Graph, canonical, robots, JSON-LD) and the brand colours (BrandPalette) are set
    on <html>, so link previews, search engines and the first paint are right before JavaScript runs.
--}}
@php
    $template = ($page['props']['store']['template'] ?? null) === 'butcher' ? 'butcher' : 'freshMarket';
    $meta = \App\Http\Support\Store\StoreMeta::for($page);
    $palette = $page['props']['store']['palette'] ?? null;
    $tokens = [
        'brand' => '--color-brand', 'onBrand' => '--color-on-brand', 'brandHover' => '--color-brand-hover', 'brandText' => '--color-brand-text',
        'brandDeep' => '--color-brand-deep', 'brandSoft' => '--color-brand-soft', 'brandTint' => '--color-brand-tint',
        'highlight' => '--color-lime', 'onHighlight' => '--color-on-lime', 'highlightSoft' => '--color-lime-soft',
    ];
    $style = is_array($palette) ? collect($tokens)->filter(fn ($var, $key) => isset($palette[$key]) && preg_match('/^#[0-9A-F]{6}$/', (string) $palette[$key]))
        ->map(fn ($var, $key) => "{$var}: {$palette[$key]}")->implode('; ') : '';
@endphp
<html lang="en-GB" data-template="{{ $template }}" @if ($style !== '') style="{{ $style }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ $meta['title'] }}</title>
        <meta name="description" content="{{ $meta['description'] }}">
        <meta name="robots" content="{{ $meta['robots'] }}">
        @if ($meta['canonical'])
            <link rel="canonical" href="{{ $meta['canonical'] }}">
        @endif
        <meta property="og:site_name" content="{{ $meta['siteName'] }}">
        <meta property="og:type" content="{{ $meta['type'] }}">
        <meta property="og:title" content="{{ $meta['title'] }}">
        <meta property="og:description" content="{{ $meta['description'] }}">
        <meta property="og:locale" content="en_GB">
        @if ($meta['canonical'])
            <meta property="og:url" content="{{ $meta['canonical'] }}">
        @endif
        @if ($meta['image'])
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ $meta['image'] }}">
        @else
            <meta name="twitter:card" content="summary">
        @endif
        <meta name="twitter:title" content="{{ $meta['title'] }}">
        <meta name="twitter:description" content="{{ $meta['description'] }}">
        @foreach ($meta['jsonLd'] as $ld)
            <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endforeach

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        @if ($template === 'butcher')
            <link href="https://fonts.bunny.net/css?family=fraunces:600,600i,700,800|inter:400,500,600,700&display=swap" rel="stylesheet" />
        @else
            <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|inter:400,500,600,700&display=swap" rel="stylesheet" />
        @endif
        @if (! empty($page['props']['store']['logoUrl']))
            <link rel="icon" href="{{ $page['props']['store']['logoUrl'] }}">
        @endif
        <meta name="theme-color" content="{{ $palette['brand'] ?? ($template === 'butcher' ? '#8F1D1D' : '#0B4A34') }}">

        @viteReactRefresh
        @vite(['resources/js/store.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="bg-paper font-sans text-ink antialiased">
        @inertia
    </body>
</html>
