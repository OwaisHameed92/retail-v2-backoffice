<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name (OS-9): the delivery order was delivered.
 */
final class OnlineOrderDeliveredMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-delivered';
    }

    public static function templateLabel(): string
    {
        return 'Online store: delivered (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when the shop marks their delivery order delivered.';
    }

    public static function sample(): static
    {
        return new self(self::deliverySample());
    }

    public function subjectLine(): string
    {
        return "Your order {$this->data->reference} was delivered";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-delivered';
    }
}
