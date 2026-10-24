<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\FeaturedMode;
use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\FeaturedProducts;
use App\Domain\OnlineStore\Support\StoreSlug;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a business's online store settings: address (slug), template, logo and banner (paths of files
 * OS-3 uploads), brand colour, headline, home sections (on or off, in order) and the email new orders go to. One
 * store per business: the first save makes it (not live, not taking orders). `template` is optional (kept when left
 * out, Fresh Market for a new store); the portal never sends it (SaveStoreBuilder drops it), only Switch & Save sets
 * it. OS-8, optional (kept when left out): `featured_mode` (FeaturedMode), `featured_product_ids` (hand-picked, the
 * business's own products, up to 24, in order) and `home_departments` (its own departments with a home row, up to 6).
 * `about_text` (up to 600 characters) is the "About us" text. The add-on price (`monthly_price`) is the admin's (OS-6), never set here. Audited.
 *
 *     app(SaveOnlineStore::class)->handle($company, ['slug' => 'kirkgate', 'template' => 'freshMarket', ...]);
 *
 * Slug rules: StoreSlug. Unique across every business; a parallel save of the same slug loses on the unique index
 * and gets the same "taken" message.
 */
final class SaveOnlineStore
{
    private const FIELDS = ['slug', 'template', 'headline', 'brand_colour', 'logo_path', 'banner_path', 'sections', 'order_email',
        'featured_mode', 'featured_product_ids', 'home_departments', 'about_text'];

    public const ABOUT_MAX = 600;

    /** OS-8: at most this many departments get their own home row. */
    public const MAX_HOME_DEPARTMENTS = 6;

    private const PATH_RULE = ['nullable', 'string', 'max:255', 'regex:/^(?!\/)(?!.*\.\.)[A-Za-z0-9_\/.-]+$/'];

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input): OnlineStore
    {
        $data = $this->validate($input, $company->id);

        return $this->tenancy->runAs($company, function () use ($company, $data): OnlineStore {
            if (self::slugTakenElsewhere($company, $data['slug'])) {
                throw self::slugTaken();
            }

            $store = OnlineStore::query()->where('company_id', $company->id)->first();
            $before = $store === null ? null : self::snapshot($store);

            try {
                $store = DB::transaction(function () use ($store, $company, $data): OnlineStore {
                    $store ??= new OnlineStore(['company_id' => $company->id, 'is_live' => false, 'taking_orders' => false]);
                    $store->fill($data)->save();

                    return $store;
                });
            } catch (UniqueConstraintViolationException $e) {
                // A parallel save took the slug first; anything else is not ours to hide.
                throw self::slugTakenElsewhere($company, $data['slug']) ? self::slugTaken() : $e;
            }

            $after = self::snapshot($store);

            if ($before !== $after) {
                $this->audit->handle($before === null ? 'online_store.created' : 'online_store.updated', $store, $before, $after, companyId: $company->id);
            }

            return $store->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, string $companyId): array
    {
        $input = array_intersect_key($input, array_flip(self::FIELDS));
        $input['slug'] = StoreSlug::normalise((string) ($input['slug'] ?? ''));
        $input['sections'] ??= StoreSection::defaults();

        $data = Validator::make($input, [
            'slug' => ['required', 'string'],
            'template' => ['sometimes', 'required', Rule::enum(StoreTemplate::class)],
            'headline' => ['nullable', 'string', 'max:160'],
            'brand_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_path' => self::PATH_RULE,
            'banner_path' => self::PATH_RULE,
            'sections' => ['array', 'max:'.count(StoreSection::cases())],
            'sections.*.key' => ['required', 'distinct', Rule::enum(StoreSection::class)],
            'sections.*.enabled' => ['required', 'boolean'],
            'order_email' => ['nullable', 'email', 'max:255'],
            'featured_mode' => ['sometimes', 'nullable', Rule::enum(FeaturedMode::class)],
            'about_text' => ['sometimes', 'nullable', 'string', 'max:'.self::ABOUT_MAX],
            'featured_product_ids' => ['sometimes', 'nullable', 'array', 'max:'.FeaturedProducts::MAX_PICKED],
            'featured_product_ids.*' => ['string', 'distinct', Rule::exists(Product::class, 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'home_departments' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_HOME_DEPARTMENTS],
            'home_departments.*' => ['string', 'distinct', Rule::exists(Department::class, 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
        ], [
            'featured_product_ids.max' => 'Choose up to '.FeaturedProducts::MAX_PICKED.' products.',
            'featured_product_ids.*.exists' => 'One of the chosen products is no longer on your tills. Remove it and save again.',
            'about_text.max' => 'Keep the "About us" text to '.self::ABOUT_MAX.' characters or fewer.',
            'home_departments.max' => 'Choose up to '.self::MAX_HOME_DEPARTMENTS.' departments.',
            'home_departments.*.exists' => 'One of the chosen departments no longer exists. Remove it and save again.',
            'brand_colour.regex' => 'Choose a colour like #1F7A4D.',
            'logo_path.regex' => 'That file path is not allowed.',
            'banner_path.regex' => 'That file path is not allowed.',
        ])->validate();

        if (($problem = StoreSlug::problem($data['slug'])) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        if (isset($data['template'])) {
            $data['template'] = StoreTemplate::from((string) $data['template']);
        }

        $data['headline'] = self::text($data['headline'] ?? null);
        $data['brand_colour'] = isset($data['brand_colour']) ? strtoupper((string) $data['brand_colour']) : null;
        $data['order_email'] = self::text($data['order_email'] ?? null);
        $data['sections'] = array_values(array_map(fn (array $s) => ['key' => (string) $s['key'], 'enabled' => (bool) $s['enabled']], (array) $data['sections']));

        if (array_key_exists('about_text', $data)) {
            $data['about_text'] = self::text($data['about_text']);
        }

        foreach (['featured_product_ids', 'home_departments'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_map('strval', (array) ($data[$list] ?? [])));
            }
        }

        return $data + ['logo_path' => null, 'banner_path' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(OnlineStore $store): array
    {
        return [
            'slug' => $store->slug,
            'template' => $store->template->value,
            'headline' => $store->headline,
            'brand_colour' => $store->brand_colour,
            'logo_path' => $store->logo_path,
            'banner_path' => $store->banner_path,
            'sections' => $store->sections,
            'order_email' => $store->order_email,
            'featured_mode' => $store->featured_mode,
            'featured_product_ids' => $store->featured_product_ids,
            'home_departments' => $store->home_departments,
            'about_text' => $store->about_text,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Slugs are unique across every business, so this one check reads other businesses' stores (the slug only).
     *
     * @phpstan-impure
     */
    private static function slugTakenElsewhere(Company $company, string $slug): bool
    {
        return OnlineStore::withoutCompanyScope()->where('slug', $slug)->where('company_id', '!=', $company->id)->exists();
    }

    private static function slugTaken(): ValidationException
    {
        return ValidationException::withMessages(['slug' => 'That address is already taken. Choose another one.']);
    }
}
