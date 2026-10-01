<?php

use App\Domain\Billing\Actions\DeleteDraftInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SqliteTestSchema;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/London'));
    $this->setVat(true);
});

test('the sequence rows exist and start at zero', function () {
    expect($this->sequenceValue('invoice'))->toBe(0)
        ->and($this->sequenceValue('payment'))->toBe(0)
        ->and($this->sequenceValue('credit_note'))->toBe(0);
});

test('invoice numbers run INV-000001, 000002… in issue order across companies', function () {
    $a = $this->payingTenant('Alpha Stores', 1, 'ALP');
    $b = $this->payingTenant('Bravo Mart', 1, 'BRV');

    $draftA = $this->draftFor($a);
    $draftB = $this->draftFor($b);

    $first = app(IssueInvoice::class)->handle($draftB);
    $second = app(IssueInvoice::class)->handle($draftA);
    $third = $this->issuedFor($a, new NewInvoice(allowOverlap: true));

    expect($first->number)->toBe('INV-000001')->and($first->sequence)->toBe(1)->and($first->company_id)->toBe($b->id)
        ->and($second->number)->toBe('INV-000002')->and($second->company_id)->toBe($a->id)
        ->and($third->number)->toBe('INV-000003')->and($third->sequence)->toBe(3)
        ->and($this->sequenceValue('invoice'))->toBe(3);
});

test('drafts have no number and never consume one, even when deleted', function () {
    $company = $this->payingTenant(tills: 1);

    $drafts = [
        $this->draftFor($company),
        $this->draftFor($company, new NewInvoice(allowOverlap: true)),
        $this->draftFor($company, new NewInvoice(allowOverlap: true)),
    ];

    expect(collect($drafts)->pluck('number')->filter()->all())->toBe([])
        ->and($this->sequenceValue('invoice'))->toBe(0);

    app(DeleteDraftInvoice::class)->handle($drafts[0]);
    app(DeleteDraftInvoice::class)->handle($drafts[1]);

    expect(app(IssueInvoice::class)->handle($drafts[2])->number)->toBe('INV-000001')
        ->and(Invoice::withoutCompanyScope()->count())->toBe(1);
});

test('a rolled-back issue gives its number back: no gaps', function () {
    $company = $this->payingTenant(tills: 1);
    $draft = $this->draftFor($company);

    try {
        DB::transaction(function () use ($draft) {
            $issued = app(IssueInvoice::class)->handle($draft);
            expect($issued->number)->toBe('INV-000001');

            throw new RuntimeException('Something failed after issuing');
        });
    } catch (RuntimeException) {
        // Expected: the whole transaction is rolled back.
    }

    $after = $this->fresh($draft);

    expect($after->status)->toBe(InvoiceStatus::Draft)
        ->and($after->number)->toBeNull()
        ->and($after->sequence)->toBeNull()
        ->and($this->sequenceValue('invoice'))->toBe(0);

    expect(app(IssueInvoice::class)->handle($after)->number)->toBe('INV-000001')
        ->and($this->sequenceValue('invoice'))->toBe(1);
});

test('an issue refused by validation takes no number', function () {
    $company = $this->payingTenant(tills: 1);
    $issued = $this->issuedFor($company);

    expect(fn () => app(IssueInvoice::class)->handle($issued))->toThrow(ValidationException::class, 'already issued')
        ->and($this->sequenceValue('invoice'))->toBe(1);
});

test('a void invoice keeps its number and the next invoice takes the following one', function () {
    $company = $this->payingTenant(tills: 1);
    $first = $this->issuedFor($company);

    [$void] = $this->voidIt($first);

    expect($void->number)->toBe('INV-000001')->and($void->status)->toBe(InvoiceStatus::Void);

    $next = $this->issuedFor($company);

    expect($next->number)->toBe('INV-000002')
        ->and($this->issuedNumbers())->toBe(['INV-000001', 'INV-000002']);
});

test('payment and credit note numbers are independent sequences', function () {
    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company); // £60.00

    $firstPayment = $this->pay($company, '10.00');
    $note = $this->creditIt($this->fresh($invoice), '5.00');
    $secondPayment = $this->pay($company, '20.00');
    $secondNote = $this->creditIt($this->fresh($invoice), '1.00');

    expect($firstPayment->payment->number)->toBe('PAY-000001')
        ->and($secondPayment->payment->number)->toBe('PAY-000002')
        ->and($note->number)->toBe('CN-000001')
        ->and($secondNote->number)->toBe('CN-000002')
        ->and($invoice->number)->toBe('INV-000001')
        ->and($this->sequenceValue('invoice'))->toBe(1)
        ->and($this->sequenceValue('payment'))->toBe(2)
        ->and($this->sequenceValue('credit_note'))->toBe(2);
});

test('parallel processes issuing drafts get INV-000001..N with no duplicates and no gaps', function () {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is not available.');
    }

    $drafts = 24;
    $workers = 6;
    $dir = storage_path('framework/testing/billing-concurrency-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($dir);
    $database = $dir.'/billing.sqlite';
    touch($database);
    $script = $dir.'/worker.php';
    file_put_contents($script, $this->concurrencyWorkerScript());

    // Explicit environment: the children only ever see the temp file database (the worker checks it too).
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'DB_URL' => false,
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'BCRYPT_ROUNDS' => '4',
        'TEST_SQLITE_DUMP' => SqliteTestSchema::existingDump($this->app) ?? '',
    ];

    try {
        $setup = new Process([PHP_BINARY, $script, base_path(), $database, 'setup', $dir.'/drafts.json', (string) $drafts], base_path(), $env, null, 120);
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue('Setup failed: '.$setup->getErrorOutput().$setup->getOutput());

        $ids = json_decode((string) file_get_contents($dir.'/drafts.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($ids)->toHaveCount($drafts);

        // Everyone boots first, then starts issuing at the same moment.
        $startAt = sprintf('%.6F', microtime(true) + 2.5);
        $processes = [];

        for ($w = 0; $w < $workers; $w++) {
            $mine = array_values(array_filter($ids, fn (int $index) => $index % $workers === $w, ARRAY_FILTER_USE_KEY));
            $process = new Process([PHP_BINARY, $script, base_path(), $database, 'issue', $dir."/out-{$w}.json", $startAt, implode(',', $mine)], base_path(), $env, null, 120);
            $process->start();
            $processes[] = $process;
        }

        $issued = [];

        foreach ($processes as $w => $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue("Worker {$w} failed: ".$process->getErrorOutput().$process->getOutput());

            $result = json_decode((string) file_get_contents($dir."/out-{$w}.json"), true, flags: JSON_THROW_ON_ERROR);
            $issued = [...$issued, ...array_values($result['numbers'])];
        }

        $expected = array_map(fn (int $n) => sprintf('INV-%06d', $n), range(1, $drafts));
        sort($issued);

        expect($issued)->toBe($expected);

        $pdo = new PDO('sqlite:'.$database);
        $rows = $pdo->query('select number, sequence, status from invoices where number is not null order by sequence')->fetchAll(PDO::FETCH_ASSOC);
        $last = (int) $pdo->query("select last_value from billing_sequences where name = 'invoice'")->fetchColumn();
        $stillDraft = (int) $pdo->query("select count(*) from invoices where status = 'draft'")->fetchColumn();
        $pdo = null;

        expect(array_column($rows, 'number'))->toBe($expected)
            ->and(array_map('intval', array_column($rows, 'sequence')))->toBe(range(1, $drafts))
            ->and(array_unique(array_column($rows, 'status')))->toBe(['issued'])
            ->and($last)->toBe($drafts)
            ->and($stillDraft)->toBe(0);
    } finally {
        File::deleteDirectory($dir);
    }
});
