<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Http\Middleware\EnsureTillContract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of a licence or device call (contract v1.4.1 §17.15, §17.7). Validates the JSON body only; unknown fields
 * are ignored (§17.11 rule 2). A failure is 400 `request.invalid` with `details.field`, never echoing values.
 */
abstract class TillApiRequest extends FormRequest
{
    public const ULID = 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public const INSTALL_CODE = 'regex:/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    public const APP_VERSION = 'regex:/^[0-9]+(\.[0-9]+){1,3}([-+][0-9A-Za-z.-]+)?$/';

    public const KID = 'regex:/^[A-Za-z0-9._-]{1,64}$/';

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
     * @throws ApiException request.invalid
     */
    protected function failedValidation(Validator $validator): never
    {
        $field = (string) array_key_first($validator->errors()->messages());

        throw LicenceApiErrors::invalid((string) $validator->errors()->first(), $field);
    }

    /**
     * The os object, kids and device fields shared by activate and validate.
     *
     * @return array<string, list<string>>
     */
    protected function tillRules(): array
    {
        return [
            'deviceName' => ['nullable', 'string', 'max:100'],
            'appVersion' => ['required', 'string', self::APP_VERSION],
            'os' => ['nullable', 'array'],
            'os.name' => ['nullable', 'string', 'max:60'],
            'os.version' => ['nullable', 'string', 'max:40'],
            'os.architecture' => ['nullable', 'string', 'max:40'],
            'tillClockUtc' => ['required', 'date'],
            'trustedKids' => ['present', 'array'],
            'trustedKids.*' => ['string', self::KID],
            'approverKids' => ['nullable', 'array'],
            'approverKids.*' => ['string', self::KID],
        ];
    }

    /**
     * The calling till. `installId` from the body, else `X-SSPOS-Install-Id`; both given and different → 400.
     *
     * @param  array<string, mixed>  $extra  More TillRequest arguments.
     *
     * @throws ApiException request.invalid
     */
    protected function till(array $extra = []): TillRequest
    {
        $data = $this->validated();
        $header = trim((string) $this->header(EnsureTillContract::INSTALL_ID_HEADER));
        $body = is_string($data['installId'] ?? null) ? $data['installId'] : '';

        if ($header !== '' && $body !== '' && strtoupper($header) !== $body) {
            throw LicenceApiErrors::invalid('X-SSPOS-Install-Id does not match installId.', 'installId');
        }

        $installId = $body !== '' ? $body : strtoupper($header);

        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $installId) !== 1) {
            throw LicenceApiErrors::invalid('The install id is missing or not a ULID.', 'installId');
        }

        $os = is_array($data['os'] ?? null) ? array_filter([
            'name' => self::text($data['os']['name'] ?? null),
            'version' => self::text($data['os']['version'] ?? null),
            'architecture' => self::text($data['os']['architecture'] ?? null),
        ], fn (?string $value) => $value !== null) : null;

        return new TillRequest(...[
            'installId' => $installId,
            'installCode' => self::text($data['installCode'] ?? null),
            'deviceName' => self::text($data['deviceName'] ?? null),
            'appVersion' => self::text($this->header(EnsureTillContract::APP_VERSION_HEADER) ?: ($data['appVersion'] ?? null)),
            'os' => $os === [] ? null : $os,
            'ip' => $this->ip(),
            'tillClockUtc' => ApiDate::parse($data['tillClockUtc'] ?? null),
            'trustedKids' => array_values(array_filter((array) ($data['trustedKids'] ?? []), 'is_string')),
            'approverKids' => array_values(array_filter((array) ($data['approverKids'] ?? []), 'is_string')),
            'contractVersion' => self::text(mb_substr((string) $this->header(EnsureTillContract::CONTRACT_HEADER), 0, 16)),
            ...$extra,
        ]);
    }

    protected static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
