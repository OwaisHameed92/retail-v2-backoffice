<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Http\Middleware\EnsureLicenceContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of a licence API call (module 1.5). Validates the JSON body only: nothing is read from the query string
 * (EnsureLicenceContract refuses one anyway). A validation failure renders 400 `request.invalid` without the
 * values sent.
 */
abstract class LicenceApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function keyAndDeviceRules(): array
    {
        return [
            'licenceKey' => ['required', 'string', 'max:64'],
            'deviceId' => ['required', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'licenceKey' => 'licence key',
            'deviceId' => 'device id',
            'deviceName' => 'device name',
            'appVersion' => 'app version',
            'requestedAt' => 'requested at',
            'tokenId' => 'token id',
            'lastSaleAt' => 'last sale at',
        ];
    }

    /**
     * The validated call. A key that is not a well-formed SSP key (wrong length or check character) can never
     * exist, so it gets the same 404 as an unknown key.
     *
     * @throws ApiException licence.not_found
     */
    public function tillRequest(): TillRequest
    {
        $data = $this->validated();
        $key = LicenceKey::tryParse((string) $data['licenceKey']);

        if ($key === null) {
            throw LicenceApiErrors::notFound();
        }

        $appVersion = trim((string) $this->header(EnsureLicenceContract::APP_VERSION_HEADER)) ?: ($data['appVersion'] ?? null);

        return new TillRequest(
            key: $key,
            deviceId: trim((string) $data['deviceId']),
            deviceName: self::text($data['deviceName'] ?? null),
            appVersion: self::text($appVersion),
            os: self::text($data['os'] ?? null),
            ip: $this->ip(),
            tokenId: self::text($data['tokenId'] ?? null),
            lastSaleAt: ApiDate::parse($data['lastSaleAt'] ?? null),
            requestedAt: ApiDate::parse($data['requestedAt'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
