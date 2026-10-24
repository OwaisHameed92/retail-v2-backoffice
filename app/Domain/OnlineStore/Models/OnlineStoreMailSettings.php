<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A store's own email settings (OS-10, the portal's Emails tab): the customer emails' sender name and Reply-To, and
 * optionally the store's own SMTP server. Written by SaveStoreMailSettings; the send results by StoreMailStatus.
 *
 * The SMTP password is encrypted at rest (encrypted cast), hidden from arrays and JSON, and never sent to the browser:
 * the portal only shows `smtp_password_last4`. A server is used for real customer emails only once a test email went
 * through it (`smtp_verified_at`); changing the server settings clears that.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_id
 * @property string|null $sender_name
 * @property string|null $reply_to
 * @property bool $smtp_enabled
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption "tls" (STARTTLS), "ssl" (implicit TLS) or "none"
 * @property string|null $smtp_username
 * @property string|null $smtp_password decrypted by the cast; never log or return it
 * @property string|null $smtp_password_last4
 * @property string|null $smtp_from_address
 * @property CarbonImmutable|null $smtp_verified_at
 * @property string|null $last_error plain words, no secrets
 * @property CarbonImmutable|null $last_error_at
 * @property CarbonImmutable|null $last_test_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineStoreMailSettings extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Ports a mail server sends on: 25 and 2525 (plain or STARTTLS), 587 (STARTTLS), 465 (SSL). */
    public const PORTS = [25, 465, 587, 2525];

    protected $table = 'online_store_mail_settings';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['smtp_password'];

    /** The store's own server is set up completely (it may still need a successful test email). */
    public function smtpComplete(): bool
    {
        return $this->smtp_enabled && filled($this->smtp_host) && $this->smtp_port !== null && filled($this->smtp_from_address)
            && filled($this->smtp_username) && filled($this->smtp_password);
    }

    /** Real customer emails go through the store's own server. */
    public function usesOwnServer(): bool
    {
        return $this->smtpComplete() && $this->smtp_verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_enabled' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'smtp_verified_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'last_test_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
