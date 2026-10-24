<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use Illuminate\Http\Response;

/**
 * `GET /{slug}/sitemap.xml` and `GET /{slug}/robots.txt` (OS-8, audit OSA-17): the store's indexable pages (home,
 * departments, products shown at its first collection shop, up to {@see self::MAX_PRODUCTS}) and the crawler rules
 * (basket, checkout, order pages and search stay out). Read only, scoped to the store's business.
 */
class StoreSitemapController extends Controller
{
    public const MAX_PRODUCTS = 10000;

    public function sitemap(StoreContext $context): Response
    {
        $store = $context->store;
        $shop = $context->shops->first();
        $urls = [route('store.home')];

        foreach ($context->departments() as $department) {
            $urls[] = route('store.department', ['department' => $department['id']]);
        }

        if ($shop !== null) {
            $ids = StoreScope::run($store, fn () => OnlineCatalogueQuery::base($shop)->orderBy('products.id')->limit(self::MAX_PRODUCTS)->pluck('products.id')->all());

            foreach ($ids as $id) {
                $urls[] = route('store.product', ['product' => (string) $id]);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        $home = rtrim(route('store.home', [], false), '/');
        $lines = ['User-agent: *'];

        foreach (['basket', 'checkout', 'order/', 'search', 'shop'] as $path) {
            $lines[] = "Disallow: {$home}/{$path}";
        }

        $lines[] = 'Sitemap: '.route('store.sitemap');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
