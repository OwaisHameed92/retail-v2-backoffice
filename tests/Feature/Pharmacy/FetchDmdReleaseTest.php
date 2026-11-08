<?php

use App\Domain\Pharmacy\Actions\FetchDmdRelease;
use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Pharmacy\Models\DmdFetchRun;
use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdReleaseStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pharmacy\DmdFixtures as Dmd;

/*
 * W4: the weekly dm+d fetch (FetchDmdRelease, `pharmacy:dmd-fetch`). TRUD is always faked (Http::fake); the real
 * service is never called. The key must never reach a log line, a stored error or the console.
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo('2026-10-26 07:30:00');
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
    });
    $this->fetch = fn () => app(FetchDmdRelease::class)->handle();
    $this->noKeyAnywhere = function () {
        $text = implode("\n", $this->logged).json_encode(DmdFetchRun::query()->get()->toArray()).json_encode(DmdRelease::query()->get()->toArray());
        $files = implode("\n", array_map(fn (string $f) => str_ends_with($f, '.txt') ? (string) Storage::disk('local')->get($f) : $f, Storage::disk('local')->allFiles()));
        expect($text.$files)->not->toContain(Dmd::KEY);
    };
});

test('off, or on with no TRUD key: nothing is called and nothing is written', function () {
    Http::fake();
    expect(($this->fetch)())->toBe(DmdFetchOutcome::Skipped);

    config(['pharmacy.dmd.enabled' => true, 'pharmacy.dmd.trud_key' => '']);
    expect(($this->fetch)())->toBe(DmdFetchOutcome::Skipped);

    Http::assertNothingSent();
    expect(DmdFetchRun::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    $this->artisan('pharmacy:dmd-fetch')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

test('a good week: fetched with our key, checked, stored unchanged with the OGL attribution, then published', function () {
    Dmd::on();
    $bytes = Dmd::zip('2026-10-26');
    Dmd::fakeTrud('2026-10-26', bytes: $bytes);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Published);

    $release = DmdRelease::newest();
    $folder = 'pharmacy/dmd/nhsbsa_dmd_10.3.0_20261026000001';

    expect($release->only(['release_id', 'name', 'week', 'archive_file_name', 'path', 'size_bytes', 'sha256']))->toBe([
        'release_id' => 'nhsbsa_dmd_10.3.0_20261026000001', 'name' => 'Release 10.3.0', 'week' => '2026-W44',
        'archive_file_name' => 'nhsbsa_dmd_10.3.0_20261026000001.zip', 'path' => "{$folder}/nhsbsa_dmd_10.3.0_20261026000001.zip",
        'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ])
        ->and($release->release_date->toDateString())->toBe('2026-10-26')
        ->and(Storage::disk('local')->get($release->path))->toBe($bytes)
        ->and(Storage::disk('local')->get("{$folder}/ATTRIBUTION.txt"))->toContain('Contains public sector information licensed under the Open Government Licence v3.0')
        ->and(DmdFetchRun::query()->sole()->only(['outcome', 'release_id', 'error']))->toBe(['outcome' => DmdFetchOutcome::Published, 'release_id' => $release->release_id, 'error' => null]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), Dmd::TRUD.'/keys/'.Dmd::KEY.'/items/24/releases'));
    ($this->noKeyAnywhere)();
});

test('idempotent: the same week again downloads nothing and writes nothing new', function () {
    Dmd::on();
    Dmd::fakeTrud('2026-10-26');
    ($this->fetch)();
    $first = DmdRelease::newest();

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Unchanged)
        ->and(Dmd::downloads())->toBe(1)
        ->and(DmdRelease::query()->count())->toBe(1)
        ->and(DmdRelease::newest()->published_at->equalTo($first->published_at))->toBeTrue();

    $this->artisan('pharmacy:dmd-fetch')->expectsOutputToContain('TRUD has nothing new')->assertSuccessful();
});

test('a zip whose SHA-256 does not match TRUD\'s is thrown away: nothing stored, the manifest keeps last week', function () {
    Dmd::on();
    $last = Dmd::publish('2026-10-19');
    Dmd::fakeTrud('2026-10-26', sha: str_repeat('a', 64));

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdRelease::newest()->id)->toBe($last->id)
        ->and(DmdRelease::query()->count())->toBe(1)
        ->and(Storage::disk('local')->directories('pharmacy/dmd'))->toBe(['pharmacy/dmd/nhsbsa_dmd_10.3.0_20261019000001'])
        ->and(DmdFetchRun::query()->sole()->error)->toContain('does not match TRUD\'s archiveFileSha256')
        ->and(implode("\n", $this->logged))->toContain('error dm+d fetch failed');

    $this->artisan('pharmacy:dmd-fetch')->expectsOutputToContain('Still serving nhsbsa_dmd_10.3.0_20261019000001.zip')->assertFailed();
    ($this->noKeyAnywhere)();
});

test('a size that differs from TRUD\'s archiveFileSizeBytes is thrown away too', function () {
    Dmd::on();
    $bytes = Dmd::zip('2026-10-26');
    $release = Dmd::trudRelease('2026-10-26', bytes: $bytes);
    Http::fake([
        Dmd::TRUD.'/keys/*' => Http::response(['releases' => [[...$release, 'archiveFileSizeBytes' => strlen($bytes) + 1]]]),
        'https://isd.digital.nhs.uk/download/*' => Http::response($bytes),
    ]);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)->and(DmdRelease::query()->count())->toBe(0);
});

test('atomic: a zip that cannot be stored in full is never named by the manifest', function () {
    Dmd::on();
    $last = Dmd::publish('2026-10-19');
    Dmd::fakeTrud('2026-10-26');
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('putFileAs')->andReturn(false);
    Storage::set('local', $disk);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdRelease::newest()->id)->toBe($last->id)
        ->and(DmdFetchRun::query()->sole()->error)->toContain('could not be stored in full');
});

test('keeps the newest week and the previous two; older weeks and stray folders go', function () {
    Dmd::on();
    foreach (['2026-09-28', '2026-10-05', '2026-10-12'] as $date) {
        Dmd::publish($date);
    }
    Storage::disk('local')->put('pharmacy/dmd/nhsbsa_dmd_9.9.9_20260101000001/half.zip', 'x');
    $this->travelTo('2026-10-19 07:30:00');
    Dmd::fakeTrud('2026-10-19');

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Published)
        ->and(DmdRelease::query()->orderBy('release_date')->pluck('week')->all())->toBe(['2026-W41', '2026-W42', '2026-W43'])
        ->and(Storage::disk('local')->directories('pharmacy/dmd'))->toEqualCanonicalizing([
            'pharmacy/dmd/nhsbsa_dmd_10.3.0_20261005000001', 'pharmacy/dmd/nhsbsa_dmd_10.3.0_20261012000001', 'pharmacy/dmd/nhsbsa_dmd_10.3.0_20261019000001',
        ])
        ->and(DmdRelease::query()->get()->every(fn (DmdRelease $r) => DmdReleaseStore::holds($r)))->toBeTrue();
});

test('TRUD refusing the key: logged and recorded without the key; nothing changes', function () {
    Dmd::on();
    Dmd::fakeTrud('2026-10-26', listStatus: 400);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdFetchRun::query()->sole()->error)->toContain('HTTP 400')
        ->and(DmdRelease::query()->count())->toBe(0);

    $this->artisan('pharmacy:dmd-fetch')->doesntExpectOutputToContain(Dmd::KEY)->assertFailed();
    ($this->noKeyAnywhere)();
});

test('TRUD unreachable: the URL in the error loses the key before it is logged or stored', function () {
    Dmd::on();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for '.Dmd::TRUD.'/keys/'.Dmd::KEY.'/items/24/releases?latest'));

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdFetchRun::query()->sole()->error)->toContain('/keys/[key]/items/24')
        ->and(implode("\n", $this->logged))->toContain('keys\\/[key]\\/items\\/24');
    ($this->noKeyAnywhere)();
});

test('a zip TRUD lists on another host is refused before any download', function () {
    Dmd::on();
    $release = Dmd::trudRelease('2026-10-26');
    Http::fake([Dmd::TRUD.'/keys/*' => Http::response(['releases' => [[...$release, 'archiveFileUrl' => 'https://evil.example/x.zip']]])]);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdFetchRun::query()->sole()->error)->toContain('host the portal does not download from (evil.example)')
        ->and(Dmd::downloads())->toBe(0)->and(DmdRelease::query()->count())->toBe(0);
});

test('an archive name that is not a plain zip name is refused', function () {
    Dmd::on();
    $release = Dmd::trudRelease('2026-10-26');
    Http::fake([Dmd::TRUD.'/keys/*' => Http::response(['releases' => [[...$release, 'archiveFileName' => '../../.env.zip']]])]);

    expect(($this->fetch)())->toBe(DmdFetchOutcome::Failed)
        ->and(DmdFetchRun::query()->sole()->error)->toContain('archive file name')
        ->and(Dmd::downloads())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('scheduled Monday with a Tuesday retry, UK time', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'pharmacy:dmd-fetch'));

    expect($events->map(fn ($event) => $event->expression)->values()->all())->toBe(['30 7 * * 1', '30 7 * * 2'])
        ->and($events->every(fn ($event) => $event->timezone === 'Europe/London'))->toBeTrue();
});
