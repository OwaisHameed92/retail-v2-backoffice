<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\BannerLink;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds or changes one home banner of the business's store (OS-8, store builder "Banners"): photo (StoreImages, the
 * banner rules; kept, replaced or removed with `remove_photo`), headline, short text, button label and where it goes
 * (BannerLink: one of the business's own departments or products, search words, or an https address), on or off. A
 * new banner goes last; a store has at most {@see OnlineStoreBanner::MAX}. Once the store has banners its old single
 * banner and headline (before OS-8) are cleared, so they never come back. Audited.
 *
 *     app(SaveStoreBanner::class)->handle($company, null, ['headline' => 'Fresh bread daily', 'link_type' => 'search', 'link_target' => 'bread'], $photo);
 */
final class SaveStoreBanner
{
    public const TOO_MANY = 'You can have up to 6 banners. Delete one first.';

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input  headline, text, button_label, link_type, link_target, is_on, remove_photo
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $bannerId, array $input, ?UploadedFile $photo = null): OnlineStoreBanner
    {
        $store = OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->first()
            ?? throw ValidationException::withMessages(['store' => SaveStoreBuilder::NOT_SWITCHED_ON]);
        $banner = $bannerId === null ? null : OnlineStoreBanner::withoutCompanyScope()->where('company_id', $company->id)
            ->where('online_store_id', $store->id)->whereKey($bannerId)->first();

        if ($bannerId !== null && $banner === null) {
            throw ValidationException::withMessages(['banner' => 'That banner no longer exists. Refresh the page.']);
        }

        $data = self::validate($company, $input);
        $remove = (bool) $data['remove_photo'];
        unset($data['remove_photo']);
        $new = $photo === null ? null : StoreImages::put($company, $photo, StoreImages::BANNER, 'photo');
        $old = $banner?->photo_path;

        try {
            $saved = $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $store, $banner, $data, $new, $old, $remove): OnlineStoreBanner {
                $count = OnlineStoreBanner::query()->where('company_id', $company->id)->where('online_store_id', $store->id)->lockForUpdate()->count();

                if ($banner === null && $count >= OnlineStoreBanner::MAX) {
                    throw ValidationException::withMessages(['banners' => self::TOO_MANY]);
                }

                $before = $banner === null ? null : self::snapshot($banner);
                $banner ??= new OnlineStoreBanner(['company_id' => $company->id, 'online_store_id' => $store->id, 'position' => $count]);
                $banner->fill([...$data, 'photo_path' => $new ?? ($remove ? null : $old)]);
                $banner->save();

                if ($store->banner_path !== null || $store->headline !== null) {
                    $store->forceFill(['banner_path' => null, 'headline' => null])->save();
                }

                if ($before !== self::snapshot($banner)) {
                    $this->audit->handle($before === null ? 'online_store.banner_added' : 'online_store.banner_updated', $banner, $before,
                        self::snapshot($banner), companyId: $company->id);
                }

                return $banner;
            }));
        } catch (Throwable $e) {
            StoreImages::delete($company, $new);

            throw $e;
        }

        if ($old !== null && $old !== $saved->photo_path) {
            StoreImages::delete($company, $old);
        }

        return $saved->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(Company $company, array $input): array
    {
        $type = BannerLink::tryFrom((string) ($input['link_type'] ?? 'none')) ?? BannerLink::None;
        $target = match ($type) {
            BannerLink::Department => ['required', 'string', Rule::exists(Department::class, 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            BannerLink::Product => ['required', 'string', Rule::exists(Product::class, 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            BannerLink::Search => ['required', 'string', 'max:80'],
            BannerLink::Url => ['required', 'string', 'max:500', 'url:https', 'starts_with:https://'],
            BannerLink::None => ['nullable'],
        };

        $data = Validator::make($input, [
            'headline' => ['nullable', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:240'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'link_type' => ['nullable', Rule::enum(BannerLink::class)],
            'link_target' => $target,
            'is_on' => ['sometimes', 'boolean'],
            'remove_photo' => ['sometimes', 'boolean'],
        ], [
            'link_target.required' => match ($type) {
                BannerLink::Department => 'Choose the department.',
                BannerLink::Product => 'Choose the product.',
                BannerLink::Search => 'Type the words to search for.',
                default => 'Type the web address, starting https://',
            },
            'link_target.exists' => $type === BannerLink::Department ? 'Choose one of your departments.' : 'Choose one of your products.',
            'link_target.url' => 'Type a full web address, starting https://',
            'link_target.starts_with' => 'Type a full web address, starting https://',
        ])->validate();

        $text = fn (string $key) => ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : $v;

        return [
            'headline' => $text('headline'),
            'text' => $text('text'),
            'button_label' => $text('button_label'),
            'link_type' => $type,
            'link_target' => $type === BannerLink::None ? null : $text('link_target'),
            'is_on' => (bool) ($data['is_on'] ?? true),
            'remove_photo' => (bool) ($data['remove_photo'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(OnlineStoreBanner $banner): array
    {
        return [
            'position' => $banner->position, 'photo_path' => $banner->photo_path, 'headline' => $banner->headline, 'text' => $banner->text,
            'button_label' => $banner->button_label, 'link_type' => $banner->link_type->value, 'link_target' => $banner->link_target,
            'is_on' => (bool) $banner->is_on,
        ];
    }
}
