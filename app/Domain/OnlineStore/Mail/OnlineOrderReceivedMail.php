<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name: "We've got your order", with the lines, the slot and a link to the order page.
 */
final class OnlineOrderReceivedMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-received';
    }

    public static function templateLabel(): string
    {
        return 'Online store: order received (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when their click & collect order is placed.';
    }

    public static function sample(): static
    {
        return new self(self::sampleData());
    }

    public function subjectLine(): string
    {
        return "We've got your order {$this->data->reference}";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-received';
    }
}
