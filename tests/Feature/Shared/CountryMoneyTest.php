<?php

use App\Domain\Ai\MorningSummary\Actions\WriteMorningNarrative;
use App\Domain\Ai\MorningSummary\Support\NarrativeCheck;
use App\Domain\Ai\Support\PromptCountry;
use App\Domain\Ai\Support\SystemPrompt;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Labels\Support\UnitPrice;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Promotions\Support\PriceTiers;
use App\Domain\Reporting\Reports\ReportCsv;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Transfers\Queries\TransferList;
use App\Http\Requests\Admin\Billing\BillingRequest;
use App\Http\Requests\Admin\Billing\CreditNoteRequest;
use App\Http\Requests\Admin\Billing\RecordPaymentRequest;

// Phase P2: every PHP money text goes through MoneyFormat. GB golden tests pin the UK output of each call-site style
// byte for byte; PK tests prove the same call sites write whole rupees with lakh grouping.

/** A Pakistan instance: COUNTRY=PK and the profile singleton rebuilt. */
function asPakistanMoney(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
}

/** The money cell of a report CSV row. */
function csvMoneyCell(string $amount): string
{
    $table = new ReportTable('t', 'T', [ReportTable::col('net', 'Net sales', 'money')], [['net' => $amount]]);
    $lines = ReportCsv::lines(new ReportResult([], [$table]), []);

    return end($lines)[0];
}

// GB golden tests: the UK output of every call-site style, unchanged.

it('keeps the UK mail money style, negatives after the symbol', function () {
    expect(MailFormat::money('1234.5'))->toBe('£1,234.50')
        ->and(MailFormat::money('0'))->toBe('£0.00')
        ->and(MailFormat::money('-5'))->toBe('£-5.00')
        ->and(MailFormat::money('-1234.567'))->toBe('£-1,234.57')
        ->and(MailFormat::money('12.345'))->toBe('£12.35')
        ->and(MailFormat::money(''))->toBe('£0.00')
        ->and(Fmt::money('-12.4'))->toBe('£-12.40')
        ->and(Fmt::pounds(1234.5))->toBe('£1,234.50');
});

it('keeps the UK billing, plan and transfer money style, sign before the symbol', function () {
    expect(BillingFormat::money('-5'))->toBe('-£5.00')
        ->and(BillingFormat::money('1234567.8'))->toBe('£1,234,567.80')
        ->and(TransferList::pounds('1234.50'))->toBe('£1,234.50')
        ->and(TransferList::pounds('-3.40'))->toBe('-£3.40');
});

it('keeps the UK call-site styles: as given, sign after the symbol, whole and cost', function () {
    expect(MoneyFormat::format('1250.5', ukStyle: MoneyFormat::AS_GIVEN))->toBe('£1250.5')
        ->and(MoneyFormat::format('-5.00', ukStyle: MoneyFormat::AS_GIVEN))->toBe('£-5.00')
        ->and(MoneyFormat::format(null, ukStyle: MoneyFormat::AS_GIVEN))->toBe('£')
        ->and(MoneyFormat::format('-1234.5', ukStyle: MoneyFormat::SIGN_AFTER_SYMBOL))->toBe('£-1,234.50')
        ->and(MoneyFormat::whole('0'))->toBe('£0')
        ->and(MoneyFormat::whole('1200'))->toBe('£1,200')
        ->and(MoneyFormat::prefix())->toBe('£')
        ->and(MoneyFormat::cost('0.4575'))->toBe('£0.4575')
        ->and(MoneyFormat::cost('1.5'))->toBe('£1.50')
        ->and(MoneyFormat::cost('0.01'))->toBe('£0.01')
        ->and(MoneyFormat::cost('12.5', ukStyle: MoneyFormat::AS_GIVEN))->toBe('£12.5');
});

it('keeps the UK label unit prices and offer text', function () {
    expect(UnitPrice::money('0.17'))->toBe('17p')
        ->and(UnitPrice::money('1.5'))->toBe('£1.50')
        ->and(UnitPrice::money('1250'))->toBe('£1250.00')
        ->and(PriceTiers::describe('2=5;3=7'))->toBe('2 for £5.00, 3 for £7.00')
        ->and(PriceTiers::describe('2=1250'))->toBe('2 for £1,250.00')
        ->and(PriceTiers::error(''))->toBe('Add at least one tier, e.g. 2 for £5.00.')
        ->and(PriceTiers::error('2=0'))->toBe('Each tier needs a price above £0.00.')
        ->and(PriceTiers::error('2=x'))->toBe('Write each tier as a quantity and a price in pounds, e.g. 2 for 5.00.');
});

it('keeps the UK validation messages with £', function () {
    expect((new CreditNoteRequest)->messages()['amount.not_regex'])->toBe('Enter an amount above £0.00.')
        ->and((new RecordPaymentRequest)->messages()['amount.not_regex'])->toBe('Enter an amount above £0.00.')
        ->and((new RecordPaymentRequest)->messages()['amount.regex'])->toBe('Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.')
        ->and(BillingRequest::cleanMoney('£1,200.50'))->toBe('1200.50');
});

it('matches each replaced UK formula byte for byte on GB', function () {
    $amounts = ['0', '0.5', '1', '-1', '9.99', '-0.004', '12.345', '-12.345', '999.995', '1000', '-1234.5', '99999.99', '1234567.891', '-7654321'];

    foreach ($amounts as $a) {
        // MailFormat / Fmt: '£'.number_format((float) $x, 2, '.', ',').
        expect(MailFormat::money($a))->toBe('£'.number_format((float) $a, 2, '.', ','))
            // PromotionSummary, PriceTiers, HourlyReport: '£'.number_format((float) $v, 2).
            ->and(MoneyFormat::format(number_format((float) $a, 2, '.', ''), ukStyle: MoneyFormat::SIGN_AFTER_SYMBOL))->toBe('£'.number_format((float) $a, 2))
            // Labels, prices, invoices, AI digests: "£{$value}".
            ->and(MoneyFormat::format($a, ukStyle: MoneyFormat::AS_GIVEN))->toBe("£{$a}");
    }

    foreach (['0.00', '1.50', '-3.40', '1234.50', '-1234567.80'] as $a) {
        // TransferList::pounds and PlanActivity::money before P2.
        [$whole, $pence] = explode('.', ltrim($a, '-'));
        $old = (str_starts_with($a, '-') ? '-' : '').'£'.strrev(implode(',', str_split(strrev($whole), 3))).'.'.$pence;

        expect(TransferList::pounds($a))->toBe($old)
            ->and(BillingFormat::money($a))->toBe($old);
    }
});

it('keeps report CSV money cells as plain decimals', function () {
    expect(csvMoneyCell('1234.50'))->toBe('1234.50')
        ->and(csvMoneyCell('-5.00'))->toBe('-5.00');
});

it('keeps UK anomaly counts and rates grouped in thousands', function () {
    expect(Fmt::number(1234567.0))->toBe('1,234,567')
        ->and(Fmt::rate(1234.56))->toBe('1,234.6')
        ->and(Fmt::rate(-0.04))->toBe(number_format(-0.04, 1, '.', ','))
        ->and(MoneyFormat::number(125000))->toBe('125,000');
});

it('sends the UK AI prompts unchanged', function () {
    expect(PromptCountry::localise(SystemPrompt::TENANT))->toBe(SystemPrompt::TENANT)
        ->and(PromptCountry::localise(SystemPrompt::ADMIN))->toBe(SystemPrompt::ADMIN)
        ->and(PromptCountry::localise(WriteMorningNarrative::PROMPT))->toBe(WriteMorningNarrative::PROMPT)
        ->and(SystemPrompt::TENANT)->toContain('Show money in pounds with the £ sign and two decimal places, for example £1,234.50.')
        ->and(SystemPrompt::TENANT)->toContain('Times are UK time.')
        ->and(NarrativeCheck::numbers('Sales were £1,234.50, up 12.0%'))->toBe(['1234.5', '12']);
});

// Pakistan: the same call sites in whole rupees, lakh grouping, one negative style.

it('writes PK mail, billing and transfer money in whole rupees with lakh grouping', function () {
    asPakistanMoney();

    expect(MailFormat::money('125000'))->toBe('Rs 1,25,000')
        ->and(MailFormat::money('1249.5'))->toBe('Rs 1,250')
        ->and(MailFormat::money('-5'))->toBe('-Rs 5')
        ->and(Fmt::money('-12.4'))->toBe('-Rs 12')
        ->and(BillingFormat::money('-5'))->toBe('-Rs 5')
        ->and(BillingFormat::money('12345678.9'))->toBe('Rs 1,23,45,679')
        ->and(TransferList::pounds('125000.00'))->toBe('Rs 1,25,000');
})->group('country-pk');

it('ignores the UK call-site styles on PK', function () {
    asPakistanMoney();

    expect(MoneyFormat::format('125000.00', ukStyle: MoneyFormat::AS_GIVEN))->toBe('Rs 1,25,000')
        ->and(MoneyFormat::format('-5.00', ukStyle: MoneyFormat::SIGN_AFTER_SYMBOL))->toBe('-Rs 5')
        ->and(MoneyFormat::whole('0'))->toBe('Rs 0')
        ->and(MoneyFormat::prefix())->toBe('Rs ')
        ->and(MoneyFormat::cost('12.5'))->toBe('Rs 12.5')
        ->and(MoneyFormat::cost('125000'))->toBe('Rs 1,25,000')
        ->and(MoneyFormat::cost('0.01'))->toBe('Rs 0.01')
        ->and(MoneyFormat::keepsUkStyles())->toBeFalse();
})->group('country-pk');

it('writes PK label prices and offers in rupees', function () {
    asPakistanMoney();

    expect(UnitPrice::money('0.17'))->toBe('Rs 0')
        ->and(UnitPrice::money('125000'))->toBe('Rs 1,25,000')
        ->and(PriceTiers::describe('2=125000'))->toBe('2 for Rs 1,25,000')
        ->and(PriceTiers::error(''))->toBe('Add at least one tier, e.g. 2 for Rs 5.')
        ->and(PriceTiers::error('2=x'))->toBe('Write each tier as a quantity and a price in rupees, e.g. 2 for 5.00.');
})->group('country-pk');

it('writes PK validation messages and reads typed rupees', function () {
    asPakistanMoney();

    expect((new CreditNoteRequest)->messages()['amount.not_regex'])->toBe('Enter an amount above Rs 0.')
        ->and((new RecordPaymentRequest)->messages()['amount.regex'])->toBe('Enter an amount in rupees with up to 2 decimal places, for example 30 or 29.99.')
        ->and(BillingRequest::cleanMoney('Rs 1,25,000'))->toBe('125000');
})->group('country-pk');

it('keeps PK report CSV money cells as plain decimals', function () {
    asPakistanMoney();

    expect(csvMoneyCell('125000.50'))->toBe('125000.50');
})->group('country-pk');

it('groups PK anomaly counts in lakhs', function () {
    asPakistanMoney();

    expect(Fmt::number(125000.0))->toBe('1,25,000')
        ->and(Fmt::rate(123456.78))->toBe('1,23,456.8')
        ->and(MoneyFormat::number(12345678))->toBe('1,23,45,678');
})->group('country-pk');

it('words the AI prompts for Pakistan', function () {
    asPakistanMoney();

    $tenant = PromptCountry::localise(SystemPrompt::TENANT);
    $admin = PromptCountry::localise(SystemPrompt::ADMIN);
    $narrative = PromptCountry::localise(WriteMorningNarrative::PROMPT);

    expect($tenant)->toContain('one shop business in Pakistan')
        ->and($tenant)->toContain('Show money in rupees with the Rs sign, in whole rupees, with lakh grouping, for example Rs 1,235 or Rs 1,23,450.')
        ->and($tenant)->toContain('Times are Pakistan time.')
        ->and($admin)->toContain('Show money in rupees with the Rs sign, in whole rupees, with lakh grouping.')
        ->and($narrative)->toContain('a shop business in Pakistan')
        ->and($narrative)->toContain('(for example "Rs 1,235" or "+12.5%")');

    foreach ([$tenant, $admin, $narrative] as $text) {
        expect($text)->not->toContain('£')
            ->and($text)->not->toContain('UK time')
            ->and($text)->not->toContain('UK shop');
    }

    expect(NarrativeCheck::numbers('Sales were Rs 1,25,000, up 12.0%'))->toBe(['125000', '12']);
})->group('country-pk');
