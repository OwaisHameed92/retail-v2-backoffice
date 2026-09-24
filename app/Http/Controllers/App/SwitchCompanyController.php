<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, SwitchCurrentCompany $switch): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'string', 'max:26'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $switch->handle($user, $validated['company_id'], $request->session());

        return redirect()->route('app.dashboard');
    }
}
