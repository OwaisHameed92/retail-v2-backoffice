<?php

use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Kid;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
});

it('generates the first key and prints only kid and public key', function () {
    $this->artisan('licence:keys:generate')
        ->expectsOutputToContain('created and active')
        ->expectsOutputToContain('Public key (x):')
        ->expectsOutputToContain('licence:keys:handover')
        ->assertSuccessful();

    $key = app(KeyStore::class)->active();

    expect($key->kid)->toBe(Kid::for($key->publicKey));
});

it('refuses to generate when an active key exists, unless --force', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();
    $first = app(KeyStore::class)->active()->kid;

    $this->artisan('licence:keys:generate')
        ->expectsOutputToContain("already exists ({$first})")
        ->assertFailed();

    expect(LicenceSigningKey::query()->count())->toBe(1);

    $this->artisan('licence:keys:generate --force')->assertSuccessful();

    expect(app(KeyStore::class)->active()->kid)->not->toBe($first)
        ->and(LicenceSigningKey::query()->where('is_active', true)->count())->toBe(1);
});

it('rotates and reports the retired key and keep period', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();
    $first = app(KeyStore::class)->active()->kid;

    $this->artisan('licence:keys:rotate')
        ->expectsOutputToContain('is now active')
        ->expectsOutputToContain("Retired {$first}: still verifies for 60 days")
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
    $first = app(KeyStore::class)->active()->kid;
    $this->travel(1)->days();
    $this->artisan('licence:keys:rotate')->assertSuccessful();
    $second = app(KeyStore::class)->active()->kid;

    $this->artisan('licence:keys:list')
        ->expectsTable(
            ['kid', 'status', 'signer cert', 'created (UTC)', 'retired (UTC)', 'verifies until (UTC)'],
            [
                [$second, 'active', 'no', '2026-09-25 09:00:00', '-', '-'],
                [$first, 'retired', 'no', '2026-09-24 09:00:00', '2026-09-25 09:00:00', '2026-11-24 09:00:00'],
            ],
        )
        ->assertSuccessful();
});

it('prunes retired keys past the keep period', function () {
    $this->artisan('licence:keys:generate')->assertSuccessful();
    $first = app(KeyStore::class)->active()->kid;
    $this->artisan('licence:keys:rotate')->assertSuccessful();
    $second = app(KeyStore::class)->active()->kid;

    $this->artisan('licence:keys:prune')->expectsOutputToContain('No expired')->assertSuccessful();

    $this->travel(60)->days();

    $this->artisan('licence:keys:prune')->expectsOutputToContain("Pruned: {$first}.")->assertSuccessful();
    expect(LicenceSigningKey::query()->pluck('kid')->all())->toBe([$second]);
});

it('schedules the prune command daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'licence:keys:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
