<?php

namespace App\Http\Controllers\App;

use App\Domain\PortalUsers\Actions\ChangeMemberAccess;
use App\Domain\PortalUsers\Actions\RemoveMember;
use App\Domain\PortalUsers\Actions\SetMemberActive;
use App\Domain\PortalUsers\Data\PortalUsersPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PortalUserAccessRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant portal's Portal users page (module 4.1): who can sign in to this business, their role and shop, and the
 * role matrix. `company.can:users.manage` (owner only). A user of another business is simply not found.
 */
class PortalUserController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/users/index', PortalUsersPage::for($this->tenancy->require(), $request->user()));
    }

    public function update(PortalUserAccessRequest $request, int $user, ChangeMemberAccess $change): RedirectResponse
    {
        $member = $this->member($user);
        $change->handle($this->tenancy->require(), $member, $request->role(), $request->branchId(), $request->user());

        return back()->with('success', "{$member->name}’s access is updated. It applies on their next page load.");
    }

    public function deactivate(Request $request, int $user, SetMemberActive $setActive): RedirectResponse
    {
        $member = $this->member($user);
        $setActive->handle($this->tenancy->require(), $member, false, $request->user());

        return back()->with('success', "{$member->name} is deactivated and can no longer open this portal.");
    }

    public function reactivate(Request $request, int $user, SetMemberActive $setActive): RedirectResponse
    {
        $member = $this->member($user);
        $setActive->handle($this->tenancy->require(), $member, true, $request->user());

        return back()->with('success', "{$member->name} can sign in again.");
    }

    public function destroy(Request $request, int $user, RemoveMember $remove): RedirectResponse
    {
        $member = $this->member($user);
        $remove->handle($this->tenancy->require(), $member, $request->user());

        return back()->with('success', "{$member->name} was removed from this business.");
    }

    /** A user of the current business (active or not), else 404. */
    private function member(int $id): User
    {
        $companyId = $this->tenancy->require()->getKey();

        return User::query()->whereHas('companies', fn (Builder $query) => $query->whereKey($companyId))->findOrFail($id);
    }
}
