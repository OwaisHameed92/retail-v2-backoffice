<?php

namespace App\Domain\OnlineStore\Legal;

/**
 * The store's generated legal pages (OS-11, audit OSA-2) as the public `store/legal` page draws them: a title, a short
 * intro and sections of plain paragraphs. Terms of sale (TermsOfSale) and the privacy notice (PrivacyNotice) have
 * their own templates; the contact page and the cookies page are here. The cookies page says the store sets only
 * strictly necessary cookies (the session that keeps the basket and checkout, and the form security token), so no
 * consent banner is needed (PECR reg. 6(4)). The shop's own extra text is added by the caller, below the template.
 */
final class LegalPages
{
    /**
     * @return array{title: string, intro: string, sections: list<array{heading: string, paragraphs: list<string>}>}
     */
    public static function document(LegalPage $page, LegalFacts $facts): array
    {
        $who = $facts->trader->tradingName;

        return match ($page) {
            LegalPage::Contact => ['title' => 'Contact us', 'intro' => "How to reach {$who} about an order or anything else.",
                'sections' => self::contact($facts)],
            LegalPage::Privacy => ['title' => 'Privacy notice', 'intro' => "How {$who} uses the information you give this online store.",
                'sections' => PrivacyNotice::sections($facts)],
            LegalPage::Terms => ['title' => 'Terms of sale', 'intro' => "The terms that apply when you order from {$who} online. Please read them before you order.",
                'sections' => TermsOfSale::sections($facts, max(0, (int) config('online_store.not_collected_after_hours', 48)))],
            LegalPage::Cookies => ['title' => 'Cookies', 'intro' => 'This store uses only the cookies it needs to work.',
                'sections' => self::cookies()],
        };
    }

    /**
     * @return list<array{heading: string, paragraphs: list<string>}>
     */
    private static function contact(LegalFacts $facts): array
    {
        $t = $facts->trader;
        $shops = array_map(fn (array $shop) => $shop['address'] === null ? $shop['name'] : "{$shop['name']}: {$shop['address']}", $facts->shops);

        return array_values(array_filter([
            ['heading' => 'Get in touch', 'paragraphs' => array_values(array_filter([
                $t->email === null ? null : "Email: {$t->email}",
                $t->phone === null ? null : "Phone: {$t->phone}",
                'About an order? Have your order number ready (it starts with W-, and is in your order email).',
            ]))],
            $shops === [] ? null : ['heading' => count($shops) === 1 ? 'Our shop' : 'Our shops', 'paragraphs' => $shops],
            ['heading' => 'Business details', 'paragraphs' => array_values(array_filter([
                $t->who(),
                $t->address === null ? null : "Address: {$t->address}",
                $t->companyNumber === null ? null : "Company number: {$t->companyNumber}",
                $t->vatNumber === null ? null : "VAT number: {$t->vatNumber}",
            ]))],
        ]));
    }

    /**
     * @return list<array{heading: string, paragraphs: list<string>}>
     */
    private static function cookies(): array
    {
        $session = (string) config('session.cookie');
        $hours = max(1, intdiv((int) config('session.lifetime', 120), 60));

        return [
            ['heading' => 'Only strictly necessary cookies', 'paragraphs' => [
                'This store does not use analytics, advertising or tracking cookies, and no other company sets cookies on its pages. The cookies below are strictly necessary for the store to work, so the law does not need us to ask for your consent to them.',
            ]],
            ['heading' => 'The cookies we use', 'paragraphs' => [
                "{$session}: keeps your basket, chosen shop and checkout details while you shop. It is deleted about {$hours} ".($hours === 1 ? 'hour' : 'hours').' after your last visit.',
                'XSRF-TOKEN: a security token that protects the store\'s forms from misuse by other websites. It lasts as long as the one above.',
            ]],
            ['heading' => 'Card payments', 'paragraphs' => [
                'If you pay by card online, you pay on DNA Payments\' own secure page, which has its own cookie policy.',
            ]],
            ['heading' => 'Managing cookies', 'paragraphs' => [
                'You can block or delete cookies in your browser\'s settings. If you block these cookies, the basket and checkout will not work.',
            ]],
        ];
    }
}
