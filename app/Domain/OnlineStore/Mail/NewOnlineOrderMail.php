<?php

namespace App\Domain\OnlineStore\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the shop (the store's order email, else the business's contact email; OSA-14): a customer placed a click & collect order, with
 * the lines, the slot and the customer's details. Replies go to the customer.
 */
final class NewOnlineOrderMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-new';
    }

    public static function templateLabel(): string
    {
        return 'Online store: new order (to the shop)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the business\'s order email (else its contact email) as soon as a customer places a click & collect order in its online store.';
    }

    public static function audience(): string
    {
        return 'staff';
    }

    public static function sample(): static
    {
        return new self(self::sampleData());
    }

    public function subjectLine(): string
    {
        return "New online order {$this->data->reference}";
    }

    public function envelope(): Envelope
    {
        $envelope = parent::envelope();
        $envelope->replyTo = [new Address($this->data->customerEmail, $this->data->customerName)];

        return $envelope;
    }

    protected function facts(): array
    {
        return [
            'Order' => $this->data->reference,
            'Customer' => $this->data->customerName,
            'Mobile' => $this->data->customerMobile,
            'Email' => $this->data->customerEmail,
            'Collect from' => $this->data->shopName,
            'Time' => self::slot($this->data->collectFrom, $this->data->collectTo),
            'Payment' => 'Pay at the shop',
        ];
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-new';
    }
}
