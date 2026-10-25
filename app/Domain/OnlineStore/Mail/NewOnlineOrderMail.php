<?php

namespace App\Domain\OnlineStore\Mail;

use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the shop (the store's order email, else the business's contact email; OSA-14): a customer placed an order (click &
 * collect or, OS-12, delivery), with the lines, the slot and the customer's details. Replies go to the customer.
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
        return 'Sent to the business\'s order email (else its contact email) as soon as a customer places an order (collection or delivery) in its online store.';
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
        // OS-12: a delivery order says where it goes and how it is paid, never "collect".
        $delivery = $this->data->isDelivery();

        return array_filter([
            'Order' => $this->data->reference,
            'Customer' => $this->data->customerName,
            'Mobile' => $this->data->customerMobile,
            'Email' => $this->data->customerEmail,
            ...($delivery
                ? ['Delivery from' => $this->data->shopName, 'Deliver to' => $this->data->deliveryAddress,
                    'Delivery fee' => MailFormat::money($this->data->deliveryFee)]
                : ['Collect from' => $this->data->shopName]),
            'Time' => self::slot($this->data->collectFrom, $this->data->collectTo),
            'Payment' => $delivery || $this->data->paid ? $this->data->paymentLabel : 'Pay at the shop',
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-new';
    }
}
