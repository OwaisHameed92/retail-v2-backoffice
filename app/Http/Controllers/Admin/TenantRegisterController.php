<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use App\Domain\Tenancy\Actions\ReactivateRegister;
use App\Domain\Tenancy\Actions\SetMainTill;
use App\Domain\Tenancy\Actions\UpdateRegister;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRegisterRequest;
use App\Http\Requests\Admin\UpdateRegisterRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Registers (tills) of a tenant (admin). Looked up inside the company only: another company's id is a 404.
 */
class TenantRegisterController extends Controller
{
    public function store(StoreRegisterRequest $request, Company $company, string $branch, AddRegister $addRegister): RedirectResponse
    {
        $register = $addRegister->handle(
            TenantBranchController::find($company, $branch),
            $request->input('name'),
            $request->input('code'),
            $request->boolean('is_main_till'),
        );

        return back()->with('success', "{$register->name} added.");
    }

    public function update(UpdateRegisterRequest $request, Company $company, string $register, UpdateRegister $updateRegister): RedirectResponse
    {
        $model = $updateRegister->handle(self::find($company, $register), (string) $request->input('name'), (string) $request->input('code'));

        return back()->with('success', "{$model->name} saved.");
    }

    public function main(Company $company, string $register, SetMainTill $setMainTill): RedirectResponse
    {
        $model = $setMainTill->handle(self::find($company, $register));

        return back()->with('success', "{$model->name} is now the main till.");
    }

    public function deactivate(Company $company, string $register, DeactivateRegister $deactivateRegister): RedirectResponse
    {
        $model = $deactivateRegister->handle(self::find($company, $register));

        return back()->with('success', "{$model->name} is deactivated.");
    }

    public function reactivate(Company $company, string $register, ReactivateRegister $reactivateRegister): RedirectResponse
    {
        $model = $reactivateRegister->handle(self::find($company, $register));

        return back()->with('success', "{$model->name} is active again.");
    }

    private static function find(Company $company, string $registerId): Register
    {
        return Register::withoutCompanyScope()->whereBelongsTo($company)->findOrFail($registerId);
    }
}
