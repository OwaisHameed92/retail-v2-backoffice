<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Mail\Sending\SmtpHostGuard;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Emails tab's "Save" (OS-10): the store's sender name and Reply-To, and its own SMTP server. Input as checked by
 * SaveStoreMailSettingsRequest (snake_case keys). Rules kept here because they need the saved row or DNS:
 *
 * - a blank password keeps the saved one; a new one is stored encrypted with its last 4 characters for the portal;
 * - turning the own server on needs a password (new or saved) and a host that passes SmtpHostGuard (SSRF);
 * - changing the host, port, encryption, username, password or From address clears "verified": a test email has to
 *   go through before customer emails use the server again;
 * - switching the own server off keeps its details (switching back on needs no retyping); `smtp_forget` removes
 *   every server detail (the password too).
 *
 * Audited without the password (only whether it changed).
 */
final class SaveStoreMailSettings
{
    private const SERVER_FIELDS = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_address'];

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input): OnlineStoreMailSettings
    {
        return $this->tenancy->runAs($company, function () use ($company, $input): OnlineStoreMailSettings {
            $store = StoreScope::storeOf($company);
            $settings = OnlineStoreMailSettings::query()->firstOrNew(['online_store_id' => $store->id], ['company_id' => $company->id]);
            $before = $this->auditable($settings);

            $settings->fill([
                'sender_name' => self::text($input['sender_name'] ?? null),
                'reply_to' => self::text($input['reply_to'] ?? null),
            ]);

            if (($input['smtp_forget'] ?? false) === true) {
                $settings->fill(array_fill_keys([...self::SERVER_FIELDS, 'smtp_password_last4', 'smtp_verified_at', 'last_error', 'last_error_at'], null));
                $settings->smtp_enabled = false;
            } else {
                $this->fillServer($settings, $input);
            }

            if ($settings->isDirty(self::SERVER_FIELDS)) {
                $settings->smtp_verified_at = null;
            }

            $passwordChanged = $settings->isDirty('smtp_password');
            $settings->save();

            $after = $this->auditable($settings);

            if ($before !== $after || $passwordChanged) {
                $this->audit->handle('online_store.emails_saved', $settings, $before, [...$after, 'password_changed' => $passwordChanged], companyId: $company->id);
            }

            return $settings;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fillServer(OnlineStoreMailSettings $settings, array $input): void
    {
        // Off: the server details stay saved (switching it back on needs no retyping); "Remove" forgets them.
        if (($input['smtp_enabled'] ?? false) !== true) {
            $settings->smtp_enabled = false;

            return;
        }

        $host = self::text($input['smtp_host'] ?? null);
        $password = is_string($input['smtp_password'] ?? null) && $input['smtp_password'] !== '' ? $input['smtp_password'] : null;

        $settings->fill([
            'smtp_enabled' => true,
            'smtp_host' => $host === null ? null : Str::lower($host),
            'smtp_port' => isset($input['smtp_port']) && $input['smtp_port'] !== '' ? (int) $input['smtp_port'] : null,
            'smtp_encryption' => self::text($input['smtp_encryption'] ?? null),
            'smtp_username' => self::text($input['smtp_username'] ?? null),
            'smtp_from_address' => self::text($input['smtp_from_address'] ?? null),
        ]);

        if ($password !== null) {
            $settings->smtp_password = $password;
            $settings->smtp_password_last4 = mb_substr($password, -4);
        }

        $errors = [];

        if ($settings->smtp_password === null || $settings->smtp_password === '') {
            $errors['smtp_password'] = 'Enter the password for your email account.';
        }

        if ($host !== null && ($problem = SmtpHostGuard::problem($host)) !== null) {
            $errors['smtp_host'] = $problem;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * What the audit log keeps: never the password.
     *
     * @return array<string, mixed>
     */
    private function auditable(OnlineStoreMailSettings $settings): array
    {
        return [
            'sender_name' => $settings->sender_name,
            'reply_to' => $settings->reply_to,
            'smtp_enabled' => (bool) $settings->smtp_enabled,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_encryption' => $settings->smtp_encryption,
            'smtp_username' => $settings->smtp_username,
            'smtp_from_address' => $settings->smtp_from_address,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
