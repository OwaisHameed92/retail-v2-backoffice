<?php

namespace App\Domain\OnlineStore\Mail;

use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base of the online order emails (OS-4): a business's email about one click & collect order, in the shop's name
 * (sender name, sign-off, replies to the shop) on the usual branded layout, like the customer statement. Dates and
 * money follow the country profile. Never categorised: a shop's order emails always go (EmailControl).
 */
abstract class OnlineOrderMailable extends BrandedMailable
{
    public function __construct(public OnlineOrderMailData $data) {}

    /** The Markdown view under resources/views/mail. */
    abstract protected function markdownView(): string;

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
        $envelope->from = new Address((string) config('mail.from.address'), $this->data->businessName);

        if (filled($this->data->replyTo)) {
            $envelope->replyTo = [new Address((string) $this->data->replyTo, $this->data->businessName)];
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(markdown: $this->markdownView(), with: [
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
