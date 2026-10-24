<?php

namespace App\Domain\OnlineStore\Legal;

/**
 * The store's generated privacy notice (OS-11, audit OSA-2, UK GDPR art. 13): who the controller is, what the store
 * collects at checkout, why and on what lawful basis, who it is shared with (Switch & Save as the platform and email
 * processor, DNA Payments for card payments), how long it is kept (orders are anonymised after
 * `online_store.anonymise_after_months`), the customer's rights and the ICO. A template for the owner or a lawyer to
 * review; each shop can add its own text at the end.
 */
final class PrivacyNotice
{
    /**
     * @return list<array{heading: string, paragraphs: list<string>}>
     */
    public static function sections(LegalFacts $facts): array
    {
        $t = $facts->trader;
        $months = $facts->anonymiseMonths;

        return [
            ['heading' => 'Who we are', 'paragraphs' => array_values(array_filter([
                "{$t->who()} is responsible for the personal information you give this online store (the \"controller\").",
                $t->address === null ? null : "Our address: {$t->address}.",
                "For anything about your information, contact us at {$facts->contactLine()}.",
            ]))],
            ['heading' => 'What we collect', 'paragraphs' => array_values(array_filter([
                'When you order: your name, mobile number and email address, what you ordered, the shop and time you chose, and any note you add.',
                $facts->delivery ? 'For a delivery: your delivery address.' : null,
                'When you use the store: your basket and choices while you shop, and technical details such as your IP address, used to keep the store secure and to stop misuse.',
                'We do not ask for your date of birth and we do not sell age-restricted products online.',
            ]))],
            ['heading' => 'Why we use it', 'paragraphs' => [
                'To take, prepare and hand over or deliver your order, and to contact you about it (for example to say it is ready, or that an item is not available). We need this to carry out our contract with you.',
                'To keep the store safe and to prevent fraud and abuse. This is in our legitimate interests.',
                'To keep the records the law requires, for example for tax. This is a legal obligation.',
                'We do not send you marketing from this store and we do not sell your information.',
            ]],
            ['heading' => 'Who we share it with', 'paragraphs' => array_values(array_filter([
                'Switch & Save, which runs this online store and sends its emails for us. It only uses your information on our instructions.',
                $facts->card ? 'DNA Payments, which takes card payments on its own secure page. We never see or store your full card details. DNA Payments has its own privacy notice.' : null,
                'Our staff who prepare your order, and anyone the law requires us to share it with.',
            ]))],
            ['heading' => 'How long we keep it', 'paragraphs' => [
                $months > 0
                    ? "Your name, phone number, email address and delivery address are removed from an order {$months} months after it is finished. We keep the record of the sale itself for as long as the law requires (usually six years)."
                    : 'We keep your order details for as long as we need them for the order and the law requires (usually six years for sales records).',
            ]],
            ['heading' => 'Your rights', 'paragraphs' => [
                'You can ask us for a copy of your information, to correct it, to delete it, to restrict how we use it, to object to how we use it, and to receive it in a portable form. Contact us and we will reply within one month.',
                'If you are unhappy with how we use your information, you can complain to the Information Commissioner\'s Office (ICO): ico.org.uk, 0303 123 1113. We would like the chance to put it right first.',
            ]],
            ['heading' => 'Cookies', 'paragraphs' => [
                'This store uses only the cookies it needs to work. See our cookies page for details.',
            ]],
        ];
    }
}
