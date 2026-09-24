<?php

use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
});

it('generates the first key and prints only kid and public key', function () {
    $this->artisan('licence:keys:generate')
        ->expectsOutputToContain('lk2026-01 created and active')
        ->expectsOutputToContain('Public key (x):')
        ->assertSuccessful();

    expect(app(KeyStore::class)->active()->kid)->toBe('lk2026-01');
});

it('refuses to generate when an active key exists, unless --force', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();

    $this->artisan('licence:keys:generate')
        ->expectsOutputToContain('already exists (lk2026-01)')
        ->assertFailed();

    expect(LicenceSigningKey::query()->count())->toBe(1);

    $this->artisan('licence:keys:generate --force')->assertSuccessful();

    expect(app(KeyStore::class)->active()->kid)->toBe('lk2026-02')
        ->and(LicenceSigningKey::query()->where('is_active', true)->count())->toBe(1);
});

it('rotates and reports the retired key and keep period', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();

    $this->artisan('licence:keys:rotate')
        ->expectsOutputToContain('lk2026-02 is now active')
        ->expectsOutputToContain('Retired lk2026-01: still verifies for 60 days')
        ->assertSuccessful();
});

it('fails to rotate before any key exists', function () {
    $this->artisan('licence:keys:rotate')
        ->expectsOutputToContain('licence:keys:generate')
        ->assertFailed();
});

it('lists keys with status and dates', function () {
    $this->artisan('licence:keys:list')->expectsOutputToContain('No licence signing keys')->assertSuccessful();

    $this->artisan('licence:keys:generate')->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan('licence:keys:rotate')->assertSuccessful();

    $this->artisan('licence:keys:list')
        ->expectsTable(
            ['kid', 'status', 'created (UTC)', 'retired (UTC)', 'verifies until (UTC)'],
            [
                ['lk2026-02', 'active', '2026-09-25 09:00:00', '-', '-'],
                ['lk2026-01', 'retired', '2026-09-24 09:00:00', '2026-09-25 09:00:00', '2026-11-24 09:00:00'],
            ],
        )
        ->assertSuccessful();
});

it('prunes retired keys past the keep period', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();
    $this->artisan('licence:keys:rotate')->assertSuccessful();

    $this->artisan('licence:keys:prune')->expectsOutputToContain('No expired')->assertSuccessful();

    $this->travel(60)->days();

    $this->artisan('licence:keys:prune')->expectsOutputToContain('Pruned: lk2026-01.')->assertSuccessful();
    expect(LicenceSigningKey::query()->pluck('kid')->all())->toBe(['lk2026-02']);
});

it('schedules the prune command daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'licence:keys:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
