<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Legal\LegalPage;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StoreLegal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /{slug}/contact|privacy|terms|cookies` (OS-11, audit OSA-2): the store's generated legal pages with its trader
 * details and own extra text (StoreLegal). Linked in the footer and at checkout. Indexable, read only.
 */
class StoreLegalController extends Controller
{
    public function __invoke(Request $request, StoreContext $context): Response
    {
        $page = LegalPage::from((string) $request->route('legalPage'));

        return Inertia::render('store/legal', StoreLegal::page($context, $page));
    }
}
