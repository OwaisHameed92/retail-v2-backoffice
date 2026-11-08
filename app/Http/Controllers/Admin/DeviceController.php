<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Devices\Data\DeviceFilters;
use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Queries\DeviceList;
use App\Domain\Devices\Queries\DeviceSummary;
use App\Domain\Licensing\LicenceKey;
use App\Domain\TillHealth\Support\HealthThresholds;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Devices (`/admin/devices`, tenants.view): every till and handheld of every business in one list, read only.
 * The only action on a row is the existing licence Release (`admin.licences.release`, licences.manage).
 */
class DeviceController extends Controller
{
    public function index(Request $request, DeviceList $list): Response|RedirectResponse
    {
        // A full licence key must never sit in a URL (contract §17.11 rule 12): drop it, never search or echo it.
        if (LicenceKey::tryParse(trim((string) $request->query('search', ''))) !== null) {
            return redirect()->route('admin.devices.index', $request->except('search'))
                ->with('error', 'For security, search by the last 4 characters of a key. To find a full key, use the top-bar search (Ctrl K).');
        }

        $filters = DeviceFilters::fromRequest($request);
        $now = CarbonImmutable::now();
        $gate = $list->gate($filters);
        $versions = $list->versions();

        return Inertia::render('admin/devices/index', [
            'devices' => $list->paginate($request, $filters, $now),
            'summary' => DeviceSummary::compute($now, $gate, $gate === null ? [] : $versions->below($gate)),
            'filters' => [...$filters->toArray(), 'gate' => $gate],
            'kinds' => DeviceKind::options(),
            'statuses' => DeviceStatus::options(),
            'versions' => $versions->toList(),
            'clockSkewSeconds' => HealthThresholds::fromConfig()->clockSkewSeconds,
            'can' => ['release' => $request->user('admin')?->hasAbility(AdminRole::LICENCES_MANAGE) ?? false],
        ]);
    }
}
