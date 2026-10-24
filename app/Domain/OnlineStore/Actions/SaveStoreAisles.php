<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\AisleSource;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The store builder's "Aisles" (OS-11, StoreAisles): what the aisles are built from (`source`: departments,
 * categories, or null = automatic), and per aisle, in the order given: shown or hidden and a display name (up to
 * {@see self::NAME_MAX} characters; empty = the till's name in Title Case). Each aisle is one of the business's own
 * departments or categories. A photo per aisle is set or removed with photo() (StoreImages; the old file is deleted).
 * Audited.
 *
 *     app(SaveStoreAisles::class)->handle($company, ['source' => 'categories', 'aisles' => [['id' => $id, 'name' => 'Fresh fruit', 'hidden' => false]]]);
 */
final class SaveStoreAisles
{
    public const NAME_MAX = 40;

    public const MAX = 60;

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input): OnlineStore
    {
        $source = AisleSource::tryFrom((string) ($input['source'] ?? ''));
        $data = Validator::make($input, [
            'source' => ['nullable', Rule::enum(AisleSource::class)],
            'aisles' => ['present', 'array', 'max:'.self::MAX],
            'aisles.*.id' => ['required', 'string', 'distinct', 'max:64'],
            'aisles.*.name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
            'aisles.*.hidden' => ['boolean'],
        ], ['aisles.*.name.max' => 'Keep aisle names to '.self::NAME_MAX.' characters or fewer.'])->validate();

        return $this->tenancy->runAs($company, function () use ($company, $data, $source): OnlineStore {
            $store = StoreScope::storeOf($company);
            $ids = array_map(fn (array $a) => (string) $a['id'], $data['aisles']);
            $known = self::known($company, $ids);
            $photos = self::photos($store);
            $aisles = [];

            foreach ($data['aisles'] as $i => $aisle) {
                if (! isset($known[$aisle['id']])) {
                    throw ValidationException::withMessages(["aisles.{$i}.id" => 'One of the aisles is no longer on your tills. Reload the page and try again.']);
                }

                $name = trim((string) ($aisle['name'] ?? ''));
                $aisles[] = ['id' => (string) $aisle['id'], 'name' => $name === '' ? null : $name, 'photo' => $photos[$aisle['id']] ?? null,
                    'hidden' => (bool) ($aisle['hidden'] ?? false)];
            }

            // Settings of aisles not sent (the other source's, or no longer on the tills) are kept after these.
            foreach (is_array($store->aisles) ? $store->aisles : [] as $old) {
                if (is_string($old['id'] ?? null) && ! in_array($old['id'], $ids, true)) {
                    $aisles[] = $old;
                }
            }

            $before = ['aisle_source' => $store->aisle_source, 'aisles' => $store->aisles];
            $store->forceFill(['aisle_source' => $source?->value, 'aisles' => $aisles])->save();
            $this->audit->handle('online_store.aisles_updated', $store, $before, ['aisle_source' => $store->aisle_source, 'aisles' => $store->aisles], companyId: $company->id);

            return $store;
        });
    }

    /**
     * Sets (or with no file, removes) one aisle's photo.
     *
     * @throws ValidationException
     */
    public function photo(Company $company, string $aisleId, ?UploadedFile $file): OnlineStore
    {
        if (self::known($company, [$aisleId]) === []) {
            throw ValidationException::withMessages(['photo' => 'That aisle is no longer on your tills.']);
        }

        $store = StoreScope::storeOf($company);
        $new = $file === null ? null : StoreImages::put($company, $file, StoreImages::PRODUCT, 'photo');
        $aisles = is_array($store->aisles) ? $store->aisles : [];
        $old = null;
        $found = false;

        foreach ($aisles as $i => $aisle) {
            if (($aisle['id'] ?? null) === $aisleId) {
                $old = $aisle['photo'] ?? null;
                $aisles[$i]['photo'] = $new;
                $found = true;
            }
        }

        if (! $found) {
            $aisles[] = ['id' => $aisleId, 'name' => null, 'photo' => $new, 'hidden' => false];
        }

        $this->tenancy->runAs($company, fn () => $store->forceFill(['aisles' => $aisles])->save());
        StoreImages::delete($company, is_string($old) ? $old : null);
        $this->audit->handle('online_store.aisle_photo', $store, ['photo' => $old], ['photo' => $new], ['aisle' => $aisleId], companyId: $company->id);

        return $store;
    }

    /**
     * The given ids that are the business's own departments or categories.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private static function known(Company $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = Department::withoutCompanyScope()->where('company_id', $company->id)->whereIn('id', $ids)->pluck('id')
            ->merge(Category::withoutCompanyScope()->where('company_id', $company->id)->whereIn('id', $ids)->pluck('id'));

        return array_fill_keys($found->map(fn ($id) => (string) $id)->all(), true);
    }

    /**
     * @return array<string, string>
     */
    private static function photos(OnlineStore $store): array
    {
        $out = [];

        foreach (is_array($store->aisles) ? $store->aisles : [] as $aisle) {
            if (is_string($aisle['id'] ?? null) && is_string($aisle['photo'] ?? null)) {
                $out[$aisle['id']] = $aisle['photo'];
            }
        }

        return $out;
    }
}
