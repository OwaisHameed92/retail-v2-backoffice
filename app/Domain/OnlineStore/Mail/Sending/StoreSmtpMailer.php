<?php

namespace App\Domain\OnlineStore\Mail\Sending;

use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a mailer for one store's own SMTP server at send time (OS-10). Never configured globally, never cached: the
 * settings are read fresh for each message. The host is checked by SmtpHostGuard and the connection goes to the checked
 * address (TLS still verifies the certificate against the host name). 10-second timeout, so a dead server does not hold
 * the queue up for long before the platform mailer takes over.
 *
 * - `tls`: STARTTLS required (ports 587, 25, 2525).
 * - `ssl`: TLS from the first byte (port 465).
 * - `none`: no encryption is asked for (STARTTLS is still used when the server offers it).
 *
 * Tests bind a subclass that overrides transport() (an array or a failing transport): no real mail is ever sent.
 */
class StoreSmtpMailer
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly ViewFactory $views, private readonly Dispatcher $events) {}

    /**
     * @throws StoreSmtpUnavailable when the server is not set up or the host is refused
     */
    public function make(OnlineStoreMailSettings $settings): Mailer
    {
        if (! $settings->smtpComplete()) {
            throw new StoreSmtpUnavailable('Your own email server is not set up completely.');
        }

        return new Mailer('online-store-smtp', $this->views, $this->transport($settings), $this->events);
    }

    protected function transport(OnlineStoreMailSettings $settings): TransportInterface
    {
        $host = strtolower(trim((string) $settings->smtp_host));
        $ip = SmtpHostGuard::safeAddress($host);
        $encryption = $settings->smtp_encryption ?? 'tls';

        $transport = new EsmtpTransport(str_contains($ip, ':') ? "[{$ip}]" : $ip, (int) $settings->smtp_port, $encryption === 'ssl');
        $transport->setUsername((string) $settings->smtp_username);
        $transport->setPassword((string) $settings->smtp_password);
        $transport->setRequireTls($encryption === 'tls');
        $transport->setLocalDomain((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
            $stream->setStreamOptions(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'SNI_server_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        return $transport;
    }
}
