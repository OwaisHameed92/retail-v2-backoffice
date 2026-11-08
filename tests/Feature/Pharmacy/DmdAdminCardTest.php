<?php

use App\Domain\Admin\Models\Admin;
use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Pharmacy\Models\DmdFetchRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Pharmacy\DmdFixtures as Dmd;

/*
 * W4: the read-only "dm+d release server" card on Admin → Till health: the switch, whether a TRUD key is set (never
 * the key), the release served (week, date, size, sha256 first 12) and the last error.
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->travelTo('2026-10-27 09:00:00');
    $this->admin = Admin::factory()->create();
});

test('off and empty: switch off, no key, no release, no error', function () {
    $this->actingAs($this->admin, 'admin')->get('/admin/till-health')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/till-health/index')
            ->where('dmd', ['enabled' => false, 'keyConfigured' => false, 'release' => null, 'lastRun' => null, 'lastError' => null]));
});

test('on: the key is reported as set only, with the release served and the last error', function () {
    Dmd::on();
    $release = Dmd::publish('2026-10-26');
    DmdFetchRun::query()->create(['outcome' => DmdFetchOutcome::Failed, 'error' => 'TRUD release list answered HTTP 500.', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
    DmdFetchRun::query()->create(['outcome' => DmdFetchOutcome::Unchanged, 'release_id' => $release->release_id, 'started_at' => now(), 'finished_at' => now()]);

    $response = $this->actingAs($this->admin, 'admin')->get('/admin/till-health')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dmd.enabled', true)
            ->where('dmd.keyConfigured', true)
            ->where('dmd.release', [
                'name' => 'Release 10.3.0', 'week' => '2026-W44', 'releaseDate' => '2026-10-26', 'sizeBytes' => $release->size_bytes,
                'sha256' => substr(strtoupper($release->sha256), 0, 12), 'publishedAt' => '2026-10-26T07:12:00Z',
            ])
            ->where('dmd.lastRun', ['outcome' => 'unchanged', 'at' => '2026-10-27T09:00:00Z'])
            ->where('dmd.lastError', ['message' => 'TRUD release list answered HTTP 500.', 'at' => '2026-10-26T09:00:00Z']));

    expect((string) $response->getContent())->not->toContain(Dmd::KEY);
});

test('only admins see it: guests go to the admin login, customers too', function () {
    $this->get('/admin/till-health')->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create())->get('/admin/till-health')->assertRedirect(route('admin.login'));
});
