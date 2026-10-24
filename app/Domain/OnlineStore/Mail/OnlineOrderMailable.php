<?php

namespace App\Domain\OnlineStore\Mail;

use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\OnlineStore\Mail\Sending\StoreMailServer;
use App\Domain\OnlineStore\Mail\Sending\StoreSmtpUnavailable;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Base of the online order emails (OS-4): a business's email about one click & collect order. Dates and money follow
 * the country profile. Never categorised: a shop's order emails always go (EmailControl).
 *
 * OS-10, the emails to the shop's customers (sendsInShopName(): every "customer" audience email, so a new one added
 * later works the same way) are the shop's own:
 * - the shop's look (StoreMailBrand: logo, colour, name, address, phone; `x-mail::store-message`, theme
 *   `mail.themes.online-store`), only a small "Online store by Switch & Save" line at the bottom;
 * - From name = the store's sender name (else the business name), never Switch & Save; Reply-To = the store's;
 * - sent through the store's own SMTP server when it is set up and verified (From = its address), else through the
 *   platform mailer (From = the platform's no-reply). If the own server fails, the reason is recorded and the email
 *   goes through the platform mailer, so the customer still gets it.
 *
 * The email to the shop itself (audience "staff") keeps the Switch & Save layout and the platform mailer.
 *
 * @phpstan-consistent-constructor
 */
abstract class OnlineOrderMailable extends BrandedMailable
{
    /** OS-10: set while sending through the store's own server: the From address on that server. */
    public ?string $smtpFrom = null;

    /** OS-10: the platform mailer, without trying the store's own server (the fallback, or a platform test send). */
    public bool $platformOnly = false;

    public function __construct(public OnlineOrderMailData $data)
    {
        if (static::sendsInShopName()) {
            $this->theme = 'themes.online-store';
        }
    }

    /** The Markdown view under resources/views/mail. */
    abstract protected function markdownView(): string;

    /** OS-10: an email to the shop's customer, in the shop's own name, look and (when set up) email server. */
    public static function sendsInShopName(): bool
    {
        return static::audience() === 'customer';
    }

    /**
     * OS-10: a customer email goes through the store's own SMTP server when it is set up and verified; if that fails
     * (or the host is refused), the reason is recorded and it goes through the platform mailer instead. A test send
     * uses the mailer it is given.
     *
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        if ($this->isTest || $this->platformOnly || $this->smtpFrom !== null || ! static::sendsInShopName()) {
            return parent::send($mailer);
        }

        $server = app(StoreMailServer::class);
        $settings = $server->ownServer($this->companyId());

        if ($settings === null) {
            return parent::send($mailer);
        }

        $fallback = clone $this;
        $fallback->platformOnly = true;
        $this->smtpFrom = $settings->smtp_from_address;

        try {
            return parent::send($server->mailer($settings));
        } catch (TransportExceptionInterface|StoreSmtpUnavailable $error) {
            $server->failed($settings, $error, static::templateKey());
            $fallback->emailLogId = $this->emailLogId;
            $fallback->holdChecked = true;

            return $fallback->send($mailer);
        }
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'order' => $this->data->reference];
    }

    public function envelope(): Envelope
    {
        $envelope = parent::envelope();
        $name = static::sendsInShopName() ? $this->data->sender() : $this->data->businessName;
        $envelope->from = new Address($this->smtpFrom ?? (string) config('mail.from.address'), $name);

        if (filled($this->data->replyTo)) {
            $envelope->replyTo = [new Address((string) $this->data->replyTo, $name)];
        } elseif (static::sendsInShopName()) {
            $envelope->replyTo = []; // a shop's customer never replies to Switch & Save support
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(markdown: $this->markdownView(), with: [
            'brand' => $this->data->brand(),
            'firstName' => MailFormat::firstName($this->data->customerName),
            'slot' => self::slot($this->data->collectFrom, $this->data->collectTo),
            'facts' => $this->facts(),
            'lines' => $this->lineRows(),
            'total' => MailFormat::money($this->data->itemsTotal),
            'hasWeighed' => $this->data->hasWeighed(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function facts(): array
    {
        return array_filter([
            'Order' => $this->data->reference,
            'Collect from' => $this->data->shopName,
            'Address' => $this->data->shopAddress,
            'Time' => self::slot($this->data->collectFrom, $this->data->collectTo),
            'Payment' => 'Pay at the shop when you collect',
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    /**
     * Table rows: "|" in a product name would break the Markdown table.
     *
     * @return list<array{name: string, quantity: string, price: string, total: string}>
     */
    protected function lineRows(): array
    {
        return array_map(fn (array $line) => [
            'name' => str_replace('|', '/', $line['name']),
            'quantity' => $line['weighed'] ? $line['quantity'].' kg' : $line['quantity'],
            'price' => MailFormat::money($line['unitPrice']).($line['weighed'] ? ' per kg' : ''),
            'total' => ($line['weighed'] ? 'about ' : '').MailFormat::money($line['lineTotal']),
        ], $this->data->lines);
    }

    /** "Monday 2 November, 10:00–10:30" in the country's time zone. */
    public static function slot(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $zone = Country::zone();

        return $from->setTimezone($zone)->format('l j F, H:i').'–'.$to->setTimezone($zone)->format('H:i');
    }

    /**
     * OS-10: the sample in a store's own look, sender name and Reply-To (the Emails tab's previews and test email).
     */
    public static function sampleFor(StoreMailBrand $brand, string $senderName, ?string $replyTo, ?string $companyId = null): static
    {
        return new static(static::sample()->data->withStore($brand, $senderName, $replyTo, $companyId));
    }

    /**
     * A sample order for previews and test sends (made-up people; places and numbers follow the country profile).
     */
    protected static function sampleData(?string $cancelReason = null): OnlineOrderMailData
    {
        $from = CarbonImmutable::now(Country::zone())->addDay()->setTime(10, 0);

        return new OnlineOrderMailData(
            businessName: 'Kirkgate Convenience',
            shopName: LocalText::places('Leeds'),
            shopAddress: null,
            reference: 'W-0042',
            customerName: 'Asha Patel',
            customerEmail: LocalText::domains('asha@example.co.uk'),
            customerMobile: LocalText::phone('07700 900123'),
            collectFrom: $from,
            collectTo: $from->addMinutes(30),
            lines: [
                ['name' => 'Heinz Baked Beans 415g', 'quantity' => '2', 'weighed' => false, 'unitPrice' => '1.40', 'lineTotal' => '2.80'],
                ['name' => 'Semi-skimmed milk 2 pint', 'quantity' => '1', 'weighed' => false, 'unitPrice' => '1.65', 'lineTotal' => '1.65'],
                ['name' => 'Lamb mince', 'quantity' => '0.5', 'weighed' => true, 'unitPrice' => '11.00', 'lineTotal' => '5.50'],
            ],
            itemsTotal: '9.95',
            replyTo: LocalText::domains('orders@kirkgate.co.uk'),
            orderUrl: rtrim((string) config('app.url'), '/').'/store/kirkgate/orders/sample',
            portalUrl: rtrim((string) config('sspos.portal_url'), '/').'/app/online-orders',
            cancelReason: $cancelReason,
        );
    }
}
