<?php

namespace App\Http\Controllers\App;

use App\Domain\News\Actions\SaveNewsTitle;
use App\Domain\News\Actions\SetNewsTitleActive;
use App\Domain\News\Queries\NewsTitleForm;
use App\Domain\News\Support\NewsAccess;
use App\Domain\TillData\Models\NewsTitle;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\News\NewsTitleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * News titles (module 5.8, `company.can:news.manage`): create, edit (incl. moving to another shop or every shop),
 * archive and restore. The tills get the change at their next sync; a shop a title left gets a `D`.
 */
class NewsTitleController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('app/news/title-form', NewsTitleForm::for(null, $request->query('q')));
    }

    public function store(NewsTitleRequest $request, SaveNewsTitle $save): RedirectResponse
    {
        $title = $save->handle(null, $request->titleInput());

        return redirect()->route('app.news.index', 'titles')->with('success', "{$title->name} is added. {$this->reach($title)}");
    }

    public function edit(Request $request, string $title): Response
    {
        $model = NewsTitle::query()->findOrFail($title);
        abort_unless(NewsAccess::mayView($model), 404);
        abort_unless(NewsAccess::mayEdit($model), 403);

        return Inertia::render('app/news/title-form', NewsTitleForm::for($model, $request->query('q')));
    }

    public function update(NewsTitleRequest $request, string $title, SaveNewsTitle $save): RedirectResponse
    {
        $saved = $save->handle(NewsTitle::query()->findOrFail($title), $request->titleInput());

        return redirect()->route('app.news.index', 'titles')->with('success', "{$saved->name} is saved. {$this->reach($saved)}");
    }

    public function archive(NewsTitleRequest $request, string $title, SetNewsTitleActive $set): RedirectResponse
    {
        $saved = $set->handle(NewsTitle::query()->findOrFail($title), false);

        return back()->with('success', "{$saved->name} is archived. The tills stop offering it at their next sync.");
    }

    public function restore(NewsTitleRequest $request, string $title, SetNewsTitleActive $set): RedirectResponse
    {
        $saved = $set->handle(NewsTitle::query()->findOrFail($title), true);

        return back()->with('success', "{$saved->name} is back on sale. {$this->reach($saved)}");
    }

    private function reach(NewsTitle $title): string
    {
        return NewsAccess::shopOf($title) === null
            ? 'Every shop\'s tills get it at their next sync.'
            : 'That shop\'s tills get it at their next sync.';
    }
}
