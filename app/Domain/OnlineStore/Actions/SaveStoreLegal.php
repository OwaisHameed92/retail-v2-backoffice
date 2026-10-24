<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Legal\LegalPage;
use App\Domain\OnlineStore\Legal\StoreTrader;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The portal's "Legal & contact" tab (OS-11, audit OSA-2): the trader details the store's legal pages, footer and
 * checkout show (StoreTrader), and the shop's own extra text added to the end of each generated page (`extra.contact`,
 * `extra.privacy`, `extra.terms`, `extra.cookies`, up to {@see self::EXTRA_MAX} characters each). Trading name, legal
 * name, address and contact email are required (going live needs them). The company number (8 letters and digits)
 * and VAT number (GB + 9 or 12 digits) are tidied to their usual form. Nothing is written when nothing changes. Audited.
 *
 *     app(SaveStoreLegal::class)->handle($company, ['trading_name' => 'Kirkgate Market', 'legal_name' => 'Kirkgate Ltd', ...]);
 */
final class SaveStoreLegal
{
    public const EXTRA_MAX = 3000;

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input): OnlineStore
    {
        $data = self::validate($input);

        return $this->tenancy->runAs($company, function () use ($company, $data): OnlineStore {
            $store = StoreScope::storeOf($company);
            $before = self::snapshot($store);
            $store->fill($data);

            if (! $store->isDirty()) {
                return $store;
            }

            $store->save();
            $this->audit->handle('online_store.legal_updated', $store, $before, self::snapshot($store), companyId: $company->id);

            return $store;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(array $input): array
    {
        $input = array_intersect_key($input, array_flip([...StoreTrader::FIELDS, 'extra']));

        foreach (StoreTrader::FIELDS as $field) {
            $input[$field] = self::text($input[$field] ?? null);
        }

        $input['company_number'] = $input['company_number'] === null ? null : strtoupper((string) preg_replace('/\s+/', '', $input['company_number']));
        $input['vat_number'] = $input['vat_number'] === null ? null : strtoupper((string) preg_replace('/[\s.-]+/', '', $input['vat_number']));

        if ($input['vat_number'] !== null && preg_match('/^\d/', $input['vat_number']) === 1) {
            $input['vat_number'] = 'GB'.$input['vat_number'];
        }

        $data = Validator::make($input, [
            'trading_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['required', 'string', 'max:160'],
            'legal_address' => ['required', 'string', 'max:500'],
            'company_number' => ['nullable', 'string', 'regex:/^(?:[0-9]{8}|[A-Z]{2}[0-9]{6})$/'],
            'vat_number' => ['nullable', 'string', 'regex:/^(?:GB|XI)(?:[0-9]{9}|[0-9]{12})$/'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'extra' => ['sometimes', 'nullable', 'array:'.implode(',', LegalPage::values())],
            'extra.*' => ['nullable', 'string', 'max:'.self::EXTRA_MAX],
        ], [
            'trading_name.required' => 'Enter the name customers know the shop by.',
            'legal_name.required' => 'Enter the business\'s legal name: the company name, or the owner\'s name for a sole trader.',
            'legal_address.required' => 'Enter the address where the business can be reached (the registered office for a company).',
            'company_number.regex' => 'A company number is 8 characters, like 01234567 or SC123456.',
            'vat_number.regex' => 'A VAT number is GB followed by 9 digits, like GB123456789.',
            'contact_email.required' => 'Enter an email address customers can contact the shop on.',
            'contact_email.email' => 'Enter a valid email address.',
            'contact_phone.regex' => 'Enter a phone number with digits, spaces and an optional + only.',
            'extra.*.max' => 'Keep each page\'s extra text to '.self::EXTRA_MAX.' characters or fewer.',
            'extra.array' => 'That page is not one of the store\'s legal pages.',
        ])->validate();

        if (array_key_exists('extra', $data)) {
            $extra = [];

            foreach (LegalPage::cases() as $page) {
                $text = self::text($data['extra'][$page->value] ?? null);

                if ($text !== null) {
                    $extra[$page->value] = $text;
                }
            }

            $data['legal_extra'] = $extra === [] ? null : $extra;
            unset($data['extra']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(OnlineStore $store): array
    {
        return [...array_combine(StoreTrader::FIELDS, array_map(fn (string $f) => $store->getAttribute($f), StoreTrader::FIELDS)),
            'legal_extra' => $store->legal_extra];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';

        return $value === '' ? null : $value;
    }
}
