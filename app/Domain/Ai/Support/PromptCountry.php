<?php

namespace App\Domain\Ai\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;

/**
 * The AI prompts' country wording (Pakistan plan P2). The prompts are written for the UK and stay byte-identical on
 * GB (they are frozen and cached). On any other profile the UK-only phrases (the shops' country, money in pounds with
 * the £ sign, UK dates and UK time) are swapped for the profile's, and "VAT" for its tax name ("GST", phase P3), still
 * frozen per instance:
 *
 *     PromptCountry::localise(SystemPrompt::TENANT); // GB: unchanged; PK: "… with the Rs sign, in whole rupees …"
 */
final class PromptCountry
{
    public static function localise(string $gbText, ?Country $country = null): string
    {
        $country ??= app(Country::class);

        if ($country->is(Country::DEFAULT)) {
            return $gbText;
        }

        $name = $country->name();
        $money = self::moneyRule($country);
        $example = MoneyFormat::format('1234.5', $country).($country->grouping() === 'lakh' ? ' or '.MoneyFormat::format('123450', $country) : '');

        return $country->taxText(strtr($gbText, [
            'Show money in pounds with the £ sign and two decimal places, for example £1,234.50.' => "{$money}, for example {$example}.",
            'Show money in pounds with the £ sign and two decimal places.' => "{$money}.",
            'Write dates the UK way, for example 3 October 2026. Times are UK time.' => "Write dates like 3 October 2026. Times are {$name} time.",
            'Write dates the UK way, for example 3 October 2026.' => 'Write dates like 3 October 2026.',
            'one UK shop business' => "one shop business in {$name}",
            'a UK shop business' => "a shop business in {$name}",
            'UK convenience shops' => "convenience shops in {$name}",
            'a UK convenience-store owner' => "a convenience-store owner in {$name}",
            'Money is in pounds as a number (12.5, not "£12.50").' => "Money is in {$country->currencyName()} as a number (12.5, not \"".MoneyFormat::prefix($country).'12.50").',
            '(UK documents write day/month/year)' => "(documents in {$name} write day/month/year)",
            '(for example "£1,234.50" or "+12.5%")' => '(for example "'.MoneyFormat::format('1234.5', $country).'" or "+12.5%")',
        ]));
    }

    /** "Show money in rupees with the Rs sign, in whole rupees, with lakh grouping". */
    private static function moneyRule(Country $country): string
    {
        $places = $country->displayDecimals() === 0
            ? ", in whole {$country->currencyName()}"
            : " and {$country->displayDecimals()} decimal places";

        return "Show money in {$country->currencyName()} with the {$country->symbol()} sign{$places}"
            .($country->grouping() === 'lakh' ? ', with lakh grouping' : '');
    }
}
