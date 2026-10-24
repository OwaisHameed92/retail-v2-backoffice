<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The online store's uploaded pictures (OS-3): logo, banner and product photos. Images only (JPEG, PNG, WebP, at most
 * 4 MB), kept on the `public` disk under `online-store/{company_id}/{folder}/` with a random name; the uploaded name is
 * never used. Audit OSA-15: the upload itself is never stored. StoreImageEncoder re-encodes it (WebP, else JPEG),
 * which strips EXIF / GPS, turns it upright and scales it down to fit MAX_SIZE for its folder (logo 600, product
 * 1200, banner 2400 pixels). A business only ever deletes its own files.
 *
 *     $path = StoreImages::put($company, $request->file('logo'), StoreImages::LOGO);
 *     StoreImages::delete($company, $oldPath);
 */
final class StoreImages
{
    public const DISK = 'public';

    public const MAX_KB = 4096;

    public const LOGO = 'logo';

    public const BANNER = 'banner';

    public const PRODUCT = 'products';

    /** Longest side, in pixels, a stored picture is scaled down to (OSA-15). */
    public const MAX_SIZE = [self::LOGO => 600, self::BANNER => 2400, self::PRODUCT => 1200];

    /** Detected upload type => its extension (what is accepted; what is stored is StoreImageEncoder's). */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Form request rules for one optional upload.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:'.implode(',', array_keys(self::TYPES)), 'max:'.self::MAX_KB,
            'dimensions:min_width=16,min_height=16,max_width=8000,max_height=8000'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.mimes" => 'Choose a JPG, PNG or WebP picture.',
            "{$field}.mimetypes" => 'Choose a JPG, PNG or WebP picture.',
            "{$field}.max" => 'Choose a picture of 4 MB or less.',
            "{$field}.dimensions" => 'Choose a JPG, PNG or WebP picture, 16 to 8000 pixels wide and high.',
            "{$field}.file" => 'The picture did not upload. Try again.',
        ];
    }

    /**
     * Re-encodes the picture (StoreImageEncoder), stores the result and returns its path on the disk.
     *
     * @throws ValidationException when the file is not a JPEG, PNG or WebP picture, or cannot be processed
     */
    public static function put(Company $company, UploadedFile $file, string $folder, string $field = 'file'): string
    {
        $type = self::TYPES[(string) $file->getMimeType()] ?? null;
        $max = self::MAX_SIZE[$folder] ?? null;
        $source = $file->getRealPath();

        if ($type === null || $max === null || $source === false) {
            throw ValidationException::withMessages([$field => 'Choose a JPG, PNG or WebP picture.']);
        }

        $image = StoreImageEncoder::encode($source, $max, $field);
        $path = self::folder($company).'/'.$folder.'/'.Str::lower((string) Str::ulid()).'.'.$image['extension'];

        if (! Storage::disk(self::DISK)->put($path, $image['bytes'])) {
            throw ValidationException::withMessages([$field => 'The picture could not be saved. Try again.']);
        }

        return $path;
    }

    /** Deletes one of the business's own pictures; anything else (another business's, a template photo) is left alone. */
    public static function delete(Company $company, ?string $path): void
    {
        if (self::owns($company, $path)) {
            Storage::disk(self::DISK)->delete((string) $path);
        }
    }

    public static function owns(Company $company, ?string $path): bool
    {
        return $path !== null && str_starts_with($path, self::folder($company).'/') && ! str_contains($path, '..');
    }

    /** The picture's public address, or null. */
    public static function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk(self::DISK)->url($path);
    }

    public static function folder(Company $company): string
    {
        return 'online-store/'.$company->id;
    }
}
