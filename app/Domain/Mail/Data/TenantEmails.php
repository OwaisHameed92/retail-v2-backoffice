<?php

namespace App\Domain\Mail\Data;

use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Tenancy\Models\Company;

/**
 * The business page's "Emails" tab (P11, billing.manage): the held emails with the values they will send, the
 * last ones sent or discarded, whether the welcome email went, and which categories are not sent automatically.
 */
final class TenantEmails
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company): array
    {
        $held = HeldEmail::query()->waiting()->where('company_id', $company->id)->with('company')->orderBy('created_at')->orderBy('id')->get();
        $done = HeldEmail::query()->where('company_id', $company->id)->where('status', '!=', HeldEmail::HELD)->with(['company', 'actionedBy'])
            ->latest('actioned_at')->limit(10)->get();
        $welcome = EmailLog::query()->where('company_id', $company->id)->where('template', WelcomeTenantMail::templateKey())
            ->where('status', 'sent')->latest('sent_at')->first();
        $settings = EmailControl::all();

        return [
            'held' => $held->map(fn (HeldEmail $email) => HeldEmailData::row($email))->values(),
            'recent' => $done->map(fn (HeldEmail $email) => HeldEmailData::row($email))->values(),
            'welcomeHeld' => $held->contains('template', WelcomeTenantMail::templateKey()),
            'passwordHeld' => $held->contains('template', SetPasswordMail::templateKey()),
            'welcomeSentAt' => $welcome?->sent_at?->toIso8601String(),
            'manualCategories' => array_values(array_map(
                fn (EmailCategory $category) => $category->label(),
                array_filter(EmailCategory::cases(), fn (EmailCategory $category) => ! $settings[$category->value]),
            )),
        ];
    }
}
