<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Flash a toast for the next plan page and read it back as the `toast` page prop.
 */
trait PlanToasts
{
    protected function toast(Request $request, string $message, string $type = 'success'): void
    {
        $request->session()->flash('toast', ['id' => (string) Str::ulid(), 'type' => $type, 'message' => $message]);
    }

    /**
     * @return array{id: string, type: string, message: string}|null
     */
    protected function flashedToast(Request $request): ?array
    {
        $toast = $request->session()->get('toast');

        return is_array($toast) ? $toast : null;
    }
}
