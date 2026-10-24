<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name (OS-9): the delivery order is out for delivery.
 */
final class OnlineOrderOnItsWayMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-on-its-way';
    }

    public static function templateLabel(): string
    {
        return 'Online store: out for delivery (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when their delivery order leaves the shop.';
    }

    public static function sample(): static
    {
        return new self(self::deliverySample());
    }

    public function subjectLine(): string
    {
        return "Your order {$this->data->reference} is on its way";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-on-its-way';
    }
}
