<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * To the customer, in the shop's name (audit OSA-18): the shop changed an item of the order while picking it, with the new total.
 */
final class OnlineOrderChangedMail extends OnlineOrderMailable
{
    public static function templateKey(): string
    {
        return 'online-order-changed';
    }

    public static function templateLabel(): string
    {
        return 'Online store: order changed (to the customer)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to a shop\'s customer, in the shop\'s name, when the shop changes or removes an item while picking (OSA-18).';
    }

    public static function sample(): static
    {
        return new self(self::sampleData());
    }

    public function subjectLine(): string
    {
        return "We changed your order {$this->data->reference}";
    }

    protected function markdownView(): string
    {
        return 'mail.online-order-changed';
    }
}
