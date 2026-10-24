<?php

namespace Tests\Feature\OnlineStore;

use App\Domain\OnlineStore\Mail\Sending\SmtpHostGuard;
use App\Domain\OnlineStore\Mail\Sending\StoreSmtpMailer;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * OS-10 test doubles: the store's own SMTP server as an in-memory transport (or one that fails with a given error), so
 * no real mail is ever sent, and fake DNS for the SSRF guard.
 */
final class StoreMailFakes
{
    public static function smtp(?Throwable $fail = null): FakeStoreSmtp
    {
        $fake = app()->make(FakeStoreSmtp::class);
        $fake->fail = $fail;
        app()->instance(StoreSmtpMailer::class, $fake);

        return $fake;
    }

    /** smtp.*.example.com and mail.*.co.uk are public; anything with "internal" resolves to 10.0.0.5. */
    public static function dns(): void
    {
        SmtpHostGuard::resolveUsing(fn (string $host) => match (true) {
            str_contains($host, 'internal') => ['10.0.0.5'],
            str_contains($host, 'nowhere') => [],
            str_contains($host, 'metadata') => ['169.254.169.254'],
            default => ['203.0.113.10'],
        });
    }

    public static function authFailure(): TransportException
    {
        return new TransportException('Failed to authenticate on SMTP server with username "shop@kirkgate.co.uk" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted. s3cret-Pa55".".');
    }

    /**
     * A verified own server for the store, as a successful test email leaves it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function verifiedServer(OnlineStore $store, array $overrides = []): OnlineStoreMailSettings
    {
        $settings = new OnlineStoreMailSettings;
        $settings->forceFill([
            'company_id' => $store->company_id,
            'online_store_id' => $store->id,
            'sender_name' => 'Kirkgate Butchers',
            'reply_to' => 'help@kirkgate.co.uk',
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.kirkgate.co.uk',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'shop@kirkgate.co.uk',
            'smtp_password' => 's3cret-Pa55',
            'smtp_password_last4' => 'Pa55',
            'smtp_from_address' => 'orders@kirkgate.co.uk',
            'smtp_verified_at' => now(),
            ...$overrides,
        ])->save();

        return $settings;
    }
}

class FakeStoreSmtp extends StoreSmtpMailer
{
    public ?Throwable $fail = null;

    public ArrayTransport $transport;

    public int $made = 0;

    protected function transport(OnlineStoreMailSettings $settings): TransportInterface
    {
        $this->made++;
        $this->transport ??= new ArrayTransport;

        return $this->fail === null ? $this->transport : new FailingTransport($this->fail);
    }
}

final class FailingTransport implements TransportInterface
{
    public function __construct(private readonly Throwable $error) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw $this->error;
    }

    public function __toString(): string
    {
        return 'failing://';
    }
}
