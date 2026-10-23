<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The store builder's "Save changes" (OS-3): the settings go through SaveOnlineStore, and a new logo or banner is
 * stored first (StoreImages) and its path saved. Paths never come from the browser: a picture is kept, replaced by
 * the upload, or removed (`remove_logo` / `remove_banner`). When the save fails the new files are deleted; when it
 * works the replaced or removed files are deleted.
 *
 * The business only changes a store Switch & Save has switched on (OS-6 makes the row): with no store this refuses
 * (`store`), and a `template` in the input is ignored, because the look is Switch & Save's to set.
 *
 *     app(SaveStoreBuilder::class)->handle($company, $request->settings(), $request->file('logo'), $request->file('banner'));
 */
final class SaveStoreBuilder
{
    public const NOT_SWITCHED_ON = 'Your online store is not switched on yet. Contact Switch & Save to switch it on.';

    private const PICTURES = ['logo' => StoreImages::LOGO, 'banner' => StoreImages::BANNER];

    public function __construct(private readonly SaveOnlineStore $save) {}

    /**
     * @param  array<string, mixed>  $input  SaveOnlineStore's fields plus `remove_logo` / `remove_banner`
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input, ?UploadedFile $logo = null, ?UploadedFile $banner = null): OnlineStore
    {
        $current = OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->first()
            ?? throw ValidationException::withMessages(['store' => self::NOT_SWITCHED_ON]);
        unset($input['template']);
        $uploads = ['logo' => $logo, 'banner' => $banner];
        $stored = [];
        $paths = [];

        try {
            foreach (self::PICTURES as $kind => $folder) {
                $old = $current->{"{$kind}_path"};
                $file = $uploads[$kind];

                $paths["{$kind}_path"] = match (true) {
                    $file !== null => $stored[] = StoreImages::put($company, $file, $folder, $kind),
                    (bool) ($input["remove_{$kind}"] ?? false) => null,
                    default => $old,
                };
            }

            $store = $this->save->handle($company, [...$input, ...$paths]);
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                StoreImages::delete($company, $path);
            }

            throw $e;
        }

        foreach (array_keys(self::PICTURES) as $kind) {
            $old = $current->{"{$kind}_path"};

            if ($old !== null && $old !== $store->{"{$kind}_path"}) {
                StoreImages::delete($company, $old);
            }
        }

        return $store;
    }
}
