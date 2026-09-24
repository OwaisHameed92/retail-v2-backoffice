<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an admin whose account was deactivated while they were signed in.
 * Runs after "auth:admin" on every protected admin route.
 */
class AdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin || ! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Your admin account is not active.']);
        }

        return $next($request);
    }
}
