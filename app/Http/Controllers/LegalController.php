<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public legal pages (module 7.7): terms, privacy notice, data processing agreement and sub-processors, each rendered
 * from `resources/legal/<page>.md` (raw HTML in the file is escaped). The owner supplies the final text.
 */
class LegalController extends Controller
{
    public const PAGES = [
        'terms' => 'Terms of service',
        'privacy' => 'Privacy notice',
        'dpa' => 'Data processing agreement',
        'subprocessors' => 'Sub-processors',
    ];

    public function __invoke(string $page): Response
    {
        $path = resource_path("legal/{$page}.md");
        abort_unless(isset(self::PAGES[$page]) && is_file($path), 404);

        return Inertia::render('legal/show', [
            'page' => $page,
            'title' => self::PAGES[$page],
            'html' => (string) Str::markdown((string) file_get_contents($path), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'pages' => array_map(fn (string $key, string $title) => ['key' => $key, 'title' => $title], array_keys(self::PAGES), self::PAGES),
        ]);
    }
}
