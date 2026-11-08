<?php

namespace App\Domain\Devices\Queries;

use App\Domain\Devices\Data\DeviceFilters;
use App\Domain\Devices\Data\DevicePresenter;
use App\Domain\Devices\Models\DeviceRow;
use App\Domain\Devices\Support\EposVersions;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Queries\LicenceQuery;
use App\Domain\Shared\Support\TableQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin Devices list: tills (TillDevices) and handhelds (HandheldDevices) of every business in one searchable,
 * sortable, paged table (a union wrapped in DeviceRow for TableQuery). Search: business, shop, till or user name,
 * till number, install id, PC / device name, licence key last 4. Filters: DeviceFilters.
 */
final class DeviceList
{
    public const SEARCHABLE = [
        'devices.company_name', 'devices.branch_name', 'devices.branch_code', 'devices.name', 'devices.code',
        'devices.install_id', 'devices.device_name', 'devices.key_last4',
    ];

    public const SORTABLE = ['company_name', 'name', 'last_seen_at'];

    public function __construct(private readonly EposVersions $versions) {}

    /**
     * @return array{data: list<mixed>, meta: array{page: int, perPage: int, total: int, lastPage: int, search: string|null, sort: string|null, direction: string}}
     */
    public function paginate(Request $request, DeviceFilters $filters, CarbonImmutable $now): array
    {
        $table = TableQuery::from($request)->searchable(self::SEARCHABLE)->sortable(self::SORTABLE)->defaultSort('company_name');
        $query = $this->query($filters, $now);

        // Tie-break on the device name inside a business before the stable id order TableQuery adds.
        if ($table->sort() === null) {
            $query->orderBy('devices.company_name')->orderBy('devices.branch_name')->orderBy('devices.name');
        }

        $page = $table->paginate($query);
        $licences = $this->licencesFor($page['data']);
        $gate = $this->gate($filters);
        $page['data'] = array_map(fn (DeviceRow $row) => DevicePresenter::row($row, $licences[$row->id] ?? null, $now, $gate), $page['data']);

        return $page;
    }

    /**
     * @return Builder<DeviceRow>
     */
    public function query(DeviceFilters $filters, CarbonImmutable $now): Builder
    {
        $parts = [];

        if ($filters->includesTills()) {
            $parts[] = TillDevices::query($filters->status, $now, $filters->oldVersion ? $this->oldVersions($filters) : null);
        }

        if ($filters->includesHandhelds()) {
            $parts[] = HandheldDevices::query($filters->status);
        }

        $union = array_shift($parts) ?? TillDevices::query(null, $now)->whereRaw('1 = 0');

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DeviceRow::query()->fromSub($union, 'devices')
            ->when($filters->notSeen, fn (Builder $q) => $q->where('devices.last_seen_at', '<', $now->subDays($filters->days)));
    }

    public function versions(): EposVersions
    {
        return $this->versions;
    }

    /** The version the "old EPOS version" filter compares with: the one typed, else the highest seen. */
    public function gate(DeviceFilters $filters): ?string
    {
        return $filters->below ?? $this->versions->highest();
    }

    /**
     * @return list<string>
     */
    private function oldVersions(DeviceFilters $filters): array
    {
        $gate = $this->gate($filters);

        return $gate === null ? [] : $this->versions->below($gate);
    }

    /**
     * The page's till licences with their company, branch and till, to work out each status like the licence page.
     *
     * @param  list<mixed>  $rows
     * @return array<string, Licence>
     */
    private function licencesFor(array $rows): array
    {
        $ids = collect($rows)->filter(fn (DeviceRow $row) => $row->kind === 'till')->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return LicenceQuery::admin()->whereIn('licences.id', $ids)->get()->keyBy('id')->all();
    }
}
