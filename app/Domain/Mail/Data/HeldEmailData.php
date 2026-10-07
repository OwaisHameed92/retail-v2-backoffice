<?php

namespace App\Domain\Mail\Data;

use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Mail\Support\HeldEmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A held email for the admin screens (P11): who it goes to, the subject and the values it will send, worked out
 * from the message as it would go now (an invoice's current amount due), so the owner can check them before
 * pressing Send. Never the licence keys themselves (only their last 4 characters) or a password link.
 */
final class HeldEmailData
{
    private const MONEY = ['total', 'balance', 'amount', 'gross', 'net'];

    private const LABELS = [
        'business' => 'Business',
        'invoice' => 'Invoice',
        'total' => 'Total',
        'balance' => 'Amount due',
        'due' => 'Due date',
        'tills' => 'Tills',
        'branches' => 'Branches',
        'trial_days' => 'Trial days',
        'purpose' => 'Link for',
        'reminder' => 'Reminder',
        'resent' => 'Sent before',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(HeldEmail $held): array
    {
        $facts = [];
        $error = null;

        if ($held->isWaiting()) {
            try {
                $message = app(HeldEmailMessage::class)->forPreview($held);
                $facts = self::facts($message->logMeta());
                $subject = $message->subjectLine();

                if ($message instanceof WelcomeTenantMail) {
                    $facts[] = ['label' => 'Licence keys', 'value' => implode(' · ', array_map(
                        fn (TillKeyData $till) => "{$till->branchName}, {$till->tillName} (…".Str::substr($till->licenceKey, -4).')',
                        $message->data->tills,
                    ))];
                }
            } catch (ValidationException $exception) {
                $error = (string) collect($exception->errors())->flatten()->first();
            }
        }

        return [
            'id' => $held->id,
            'company' => $held->company !== null ? ['id' => $held->company->id, 'name' => $held->company->name] : null,
            'category' => $held->category->value,
            'categoryLabel' => $held->category->label(),
            'template' => $held->template,
            'templateLabel' => EmailTemplates::label($held->template),
            'to' => $held->to,
            'subject' => $subject ?? $held->subject,
            'facts' => $facts,
            'problem' => $error,
            'status' => $held->status,
            'createdAt' => $held->created_at?->toIso8601String(),
            'actionedAt' => $held->actioned_at?->toIso8601String(),
            'actionedBy' => $held->actionedBy?->name,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $meta
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $meta): array
    {
        $facts = [];

        foreach ($meta as $key => $value) {
            if ($value === null || $value === '' || $key === 'test') {
                continue;
            }

            $text = match (true) {
                in_array($key, self::MONEY, true) && is_numeric($value) => BillingFormat::money((string) $value),
                $key === 'due' && is_string($value) => CarbonImmutable::parse($value)->format('j M Y'),
                is_bool($value) => $value ? 'Yes' : 'No',
                $key === 'purpose' => $value === 'setup' ? 'First password (a new 7-day link is made when sent)' : 'Password reset',
                default => (string) $value,
            };

            $facts[] = ['label' => self::LABELS[$key] ?? Str::ucfirst(str_replace('_', ' ', (string) $key)), 'value' => $text];
        }

        return $facts;
    }
}
