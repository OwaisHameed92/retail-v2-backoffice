<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name: the order is packed and ready to collect (and to pay for at the shop).
 */
final class OnlineOrderReadyMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-ready';
    }

    public static function templateLabel(): string
    {
        return 'Online store: ready to collect (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when the shop marks their click & collect order ready.';
    }

    public static function sample(): static
    {
        return new self(self::sampleData());
    }

    public function subjectLine(): string
    {
        return "Your order {$this->data->reference} is ready to collect";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-ready';
    }
}
