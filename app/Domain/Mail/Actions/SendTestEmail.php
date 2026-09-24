<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\Mail;

/**
 * Queues a sample of a template to the signed-in admin's own address (never anyone else) and audits it.
 * The subject gets a "[Test]" prefix and the log row is marked as a test.
 */
final class SendTestEmail
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function handle(Admin $admin, BrandedMailable $mailable): void
    {
        $mailable->asTest()->to($admin->email, $admin->name);

        // Test sends skip the template's fixed recipients (staff inbox): only the admin gets it.
        Mail::queue($mailable);

        $this->recordAudit->handle(
            'email.test_sent',
            $admin,
            meta: ['template' => $mailable::templateKey(), 'to' => $admin->email],
            actor: $admin,
        );
    }
}
