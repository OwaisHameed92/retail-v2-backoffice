<?php

namespace App\Domain\OnlineStore\Legal;

/**
 * The store's generated "Terms of sale" (OS-11, audit OSA-2): a plain-English UK template with the trader's details
 * and what the store offers (collection, local delivery, card payments by DNA Payments, paying at the shop or on
 * delivery) filled in. Covers who sells, what is and is not sold online, how an order becomes a contract, collection,
 * delivery, payment, cancelling and refunds (Consumer Contracts Regulations 2013, perishable goods exempt), faulty
 * goods (Consumer Rights Act 2015), complaints and the law. The wording is a template for Switch & Save's owner or a
 * lawyer to review; each shop can add its own terms at the end.
 */
final class TermsOfSale
{
    /**
     * @return list<array{heading: string, paragraphs: list<string>}>
     */
    public static function sections(LegalFacts $facts, int $notCollectedHours): array
    {
        $t = $facts->trader;

        return array_values(array_filter([
            ['heading' => 'Who we are', 'paragraphs' => array_values(array_filter([
                "These terms apply to orders placed in this online store. The shop is run by {$t->who()}.",
                $t->address === null ? null : "Our address: {$t->address}.",
                $t->companyNumber === null ? null : "Company number: {$t->companyNumber}.",
                $t->vatNumber === null ? null : "VAT number: {$t->vatNumber}.",
                "You can contact us at {$facts->contactLine()}.",
            ]))],
            ['heading' => 'What we sell online', 'paragraphs' => [
                'The products, prices and stock come from our shop\'s tills. Prices are in pounds sterling and include VAT where it applies. Photos are for illustration; the product you receive may look slightly different.',
                'Products sold by weight show an estimated price. You pay for the actual weight, which we tell you before you pay.',
                'Some products have a limit on how many can be bought in one order, for example painkillers.',
                'We do not sell age-restricted products online: alcohol, tobacco, vapes, lottery, knives and blades, and pharmacy or prescription medicines are sold in the shop only, where we can check age and give advice.',
            ]],
            ['heading' => 'Your order', 'paragraphs' => [
                'When you place an order we email you to say we have received it. This is not yet acceptance: we may not be able to supply everything, for example if an item has just sold out.',
                'If an item is not available we will remove it from your order, or replace it with a similar item only if you agreed to substitutes at checkout. You only pay for what you receive.',
                $facts->card
                    ? 'A contract is made when we confirm your order. If you paid by card online, the contract is made when your payment is taken and we confirm the order; if we cannot supply your order, we refund you in full.'
                    : 'A contract is made when we confirm your order and you pay for it.',
            ]],
            $facts->shops === [] || ! $facts->collection ? null : ['heading' => 'Click & collect', 'paragraphs' => array_filter([
                'Choose the shop and a collection time at checkout. We email you when your order is ready. Please bring your order number.',
                $notCollectedHours > 0 ? "If an order is not collected within {$notCollectedHours} hours of the chosen time, we may cancel it and put the items back on sale." : null,
            ])],
            $facts->delivery ? ['heading' => 'Local delivery', 'paragraphs' => [
                'We deliver to the local areas shown at checkout, at the delivery fee and in the time slots shown there. Someone needs to be in to receive the order.',
                'If nobody is in, we will try to contact you and may take the order back to the shop. We will agree a new delivery time with you or refund you.',
            ]] : null,
            ['heading' => 'Paying', 'paragraphs' => array_values(array_filter([
                $facts->payAtShop ? 'Pay at the shop: you pay at the till when you collect, by any way the shop accepts.' : null,
                $facts->payOnDelivery ? 'Pay on delivery: you pay the driver when your order arrives.' : null,
                $facts->card ? 'Card online: card payments are taken by DNA Payments on their secure payment page. We never see or store your full card details.' : null,
                'Till offers and promotions are taken off when you pay at the till. Online, the shelf price is shown.',
            ]))],
            ['heading' => 'Changing or cancelling an order', 'paragraphs' => [
                "To change or cancel an order before we start preparing it, contact us at {$facts->contactLine()} with your order number.",
                'You also have a legal right to cancel an order for goods within 14 days of receiving them, without giving a reason. This right does not apply to goods that go off quickly (for example fresh food, chilled or frozen food and flowers), to sealed goods that are not suitable for return for health or hygiene reasons once unsealed, or to goods that have been mixed with other items after delivery.',
                'To cancel, tell us clearly (by email or phone) within the 14 days and return the goods unused, in their packaging, to the shop. We refund you within 14 days of getting the goods back, to the way you paid. You may need to pay the cost of returning goods that were delivered.',
            ]],
            ['heading' => 'Faulty or wrong items', 'paragraphs' => [
                'If something is faulty, past its date or not what you ordered, tell us as soon as possible and we will put it right with a replacement or a refund. This does not affect your legal rights under the Consumer Rights Act 2015.',
            ]],
            ['heading' => 'Our responsibility to you', 'paragraphs' => [
                'We are responsible for loss or damage you suffer that is a foreseeable result of us breaking these terms or failing to use reasonable care. We do not exclude or limit our liability where it would be unlawful to do so, including for death or personal injury caused by our negligence.',
            ]],
            ['heading' => 'Complaints and the law', 'paragraphs' => [
                "If you are not happy, contact us at {$facts->contactLine()} and we will try to resolve it quickly.",
                'These terms are governed by the law of England and Wales. If you live in Scotland or Northern Ireland, you can also bring proceedings in your local courts and the law of your home nation applies where it gives you more protection.',
            ]],
        ]));
    }
}
