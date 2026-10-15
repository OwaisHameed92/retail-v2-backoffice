<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\SendPaymentReminders;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Owner 2026-10-15 (Istanbul): an invoice a Direct Debit payment is collecting shows "By Direct Debit" on its charge
 * day in place of "Due tomorrow", and gets no payment reminder (the bank pays it).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-15 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

function directDebitCollecting(object $test): array
{
    $company = $test->payingTenant();
    $invoice = $test->issuedFor($company);
    $invoice->forceFill(['issue_date' => '2026-10-13', 'due_date' => '2026-10-16'])->save();
    $row = new GoCardlessPayment(['gc_payment_id' => 'PM000777', 'gc_mandate_id' => 'MD000777', 'kind' => PaymentKind::Subscription, 'amount' => $invoice->total, 'charge_date' => '2026-10-20', 'status' => PaymentStatus::PendingSubmission, 'invoice_id' => $invoice->id]);
    $row->company_id = $company->id;
    $row->save();

    return [$company, $invoice];
}

test('a collecting invoice shows its Direct Debit day', function () {
    [$company] = directDebitCollecting($this);

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->get(route('admin.tenants.show', $company))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('billing.invoices.data.0.directDebitOn', '2026-10-20'));
});

test('a collecting invoice gets no due today or overdue reminder', function () {
    [, $invoice] = directDebitCollecting($this);

    foreach (['2026-10-16 07:00', '2026-10-25 07:00'] as $at) {
        $this->travelTo(CarbonImmutable::parse($at, 'Europe/London'));
        app(SendPaymentReminders::class)->handle(CarbonImmutable::now());
    }

    Mail::assertNotQueued(PaymentReminderMail::class);
    expect($invoice->fresh()->due_today_reminded_at)->toBeNull();
});
