<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Actions\RecordAdminLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('admin/auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request, RecordAdminLogin $recordLogin): RedirectResponse
    {
        $admin = $request->authenticate();

        $request->session()->regenerate();

        $recordLogin->handle($admin);

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
