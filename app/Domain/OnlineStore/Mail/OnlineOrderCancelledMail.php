<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name: the shop cancelled the order, with the reason it gave. Nothing was paid
 * (pay at the shop), so there is nothing to refund.
 */
final class OnlineOrderCancelledMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-cancelled';
    }

    public static function templateLabel(): string
    {
        return 'Online store: order cancelled (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when the shop cancels their click & collect order, with the reason.';
    }

    public static function sample(): static
    {
        return new self(self::sampleData('Sorry, we have run out of lamb mince today.'));
    }

    public function subjectLine(): string
    {
        return "Your order {$this->data->reference} is cancelled";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-cancelled';
    }
}
