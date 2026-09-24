<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\Actions\SwitchCurrentBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Top-bar branch switcher: an active branch of the current company, or empty for "All branches".
 */
class SwitchBranchController extends Controller
{
    public function __invoke(Request $request, SwitchCurrentBranch $switch): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'string', 'max:26'],
        ]);

        $switch->handle($validated['branch_id'] ?? null, $request->session());

        return back();
    }
}
