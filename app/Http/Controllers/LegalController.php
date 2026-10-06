<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Country\Country;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public legal pages (module 7.7): terms, privacy notice, data processing agreement and sub-processors, each rendered
 * from `resources/legal/<page>.md` (raw HTML in the file is escaped). The owner supplies the final text.
 *
 * Pakistan plan P6: an instance whose country has its own copy (`resources/legal/<country>/<page>.md`, e.g. `pk/`) gets
 * that one; the others (GB) read the default file, so the UK pages are unchanged.
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
        abort_unless(isset(self::PAGES[$page]), 404);
        $path = self::path($page);
        abort_unless(is_file($path), 404);

        return Inertia::render('legal/show', [
            'page' => $page,
            'title' => self::PAGES[$page],
            'html' => (string) Str::markdown((string) file_get_contents($path), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'pages' => array_map(fn (string $key, string $title) => ['key' => $key, 'title' => $title], array_keys(self::PAGES), self::PAGES),
        ]);
    }

    /** The country's own copy of a page when it has one (`legal/pk/privacy.md`), else the default (`legal/privacy.md`). */
    public static function path(string $page): string
    {
        $local = resource_path('legal/'.strtolower(app(Country::class)->code())."/{$page}.md");

        return is_file($local) ? $local : resource_path("legal/{$page}.md");
    }
}
