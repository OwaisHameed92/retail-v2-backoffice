<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `/` has no page of its own (the marketing website has it): a signed-in customer goes to their portal, signed-in
 * staff to the admin area, everyone else to the sign-in. Each guard is checked by name, so `/` and `/login` can never
 * send a session back and forth (an admin session once looped `/login` ⇄ `/`).
 */
class HomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return match (true) {
            Auth::guard('web')->check() => redirect()->route('app.dashboard'),
            Auth::guard('admin')->check() => redirect()->route('admin.dashboard'),
            default => redirect()->route('login'),
        };
    }
}
