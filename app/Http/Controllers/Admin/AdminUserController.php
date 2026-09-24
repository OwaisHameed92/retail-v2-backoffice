<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Actions\CreateAdmin;
use App\Domain\Admin\Actions\DeactivateAdmin;
use App\Domain\Admin\Actions\ReactivateAdmin;
use App\Domain\Admin\Actions\UpdateAdmin;
use App\Domain\Admin\Data\AdminData;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin user management (owner only; enforced by AdminPolicy via route "can" middleware).
 */
class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $admins = Admin::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Admin $admin) => AdminData::fromModel($admin))
            ->values();

        return Inertia::render('admin/admins/index', [
            'admins' => $admins,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/admins/create', [
            'roles' => AdminRole::options(),
        ]);
    }

    public function store(StoreAdminRequest $request, CreateAdmin $createAdmin): RedirectResponse
    {
        $createAdmin->handle(
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->role(),
        );

        return redirect()->route('admin.admins.index')->with('status', 'Admin user created.');
    }

    public function edit(Request $request, Admin $admin): Response
    {
        return Inertia::render('admin/admins/edit', [
            'editAdmin' => AdminData::fromModel($admin),
            'roles' => AdminRole::options(),
            'isSelf' => $admin->is($request->user('admin')),
        ]);
    }

    public function update(UpdateAdminRequest $request, Admin $admin, UpdateAdmin $updateAdmin): RedirectResponse
    {
        $updateAdmin->handle(
            $admin,
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->role(),
            $request->newPassword(),
        );

        return redirect()->route('admin.admins.index')->with('status', 'Admin user updated.');
    }

    public function deactivate(Request $request, Admin $admin, DeactivateAdmin $deactivateAdmin): RedirectResponse
    {
        /** @var Admin $actor */
        $actor = $request->user('admin');

        $deactivateAdmin->handle($admin, $actor);

        return redirect()->route('admin.admins.index')->with('status', "{$admin->name} has been deactivated.");
    }

    public function reactivate(Admin $admin, ReactivateAdmin $reactivateAdmin): RedirectResponse
    {
        $reactivateAdmin->handle($admin);

        return redirect()->route('admin.admins.index')->with('status', "{$admin->name} has been reactivated.");
    }
}
