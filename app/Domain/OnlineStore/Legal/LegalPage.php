<?php

namespace App\Domain\OnlineStore\Legal;

/**
 * The online store's generated legal pages (OS-11, audit OSA-2), each at `/{slug}/{value}` (route `store.{value}`),
 * linked in the store's footer and at checkout. The shop may add its own text to the end of each one (SaveStoreLegal).
 */
enum LegalPage: string
{
    case Contact = 'contact';
    case Privacy = 'privacy';
    case Terms = 'terms';
    case Cookies = 'cookies';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Contact us',
            self::Privacy => 'Privacy notice',
            self::Terms => 'Terms of sale',
            self::Cookies => 'Cookies',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $page) => $page->value, self::cases());
    }
}
