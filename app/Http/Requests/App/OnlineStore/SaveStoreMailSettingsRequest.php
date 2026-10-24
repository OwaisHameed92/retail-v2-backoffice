<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Mail\SenderName;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The Emails tab's "Save" (OS-10). The route checks `store.manage`; a one-shop user may look but not change it (403).
 * Shapes and formats are checked here; the password (blank keeps the saved one) and the SSRF guard on the host are
 * checked by SaveStoreMailSettings. The password is never echoed back: `smtp_password` is in the exception handler's
 * dontFlash list (bootstrap/app.php), so it is not kept as old input.
 */
class SaveStoreMailSettingsRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $server = fn (mixed ...$rules) => ['exclude_unless:smtp_enabled,true', 'required', ...$rules];

        return [
            'sender_name' => ['nullable', 'string', 'max:70', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! SenderName::wellFormed($value)) {
                    $fail('Use one line of plain text.');
                } elseif (is_string($value) && SenderName::isPlatform($value)) {
                    $fail('Use your shop\'s own name: customer emails never come from Switch & Save.');
                }
            }],
            'reply_to' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'smtp_enabled' => ['required', 'boolean'],
            'smtp_forget' => ['sometimes', 'boolean'],
            'smtp_host' => $server('string', 'max:253'),
            'smtp_port' => $server('integer', Rule::in(OnlineStoreMailSettings::PORTS)),
            'smtp_encryption' => $server('string', Rule::in(OnlineStoreMailSettings::ENCRYPTIONS), function (string $attribute, mixed $value, Closure $fail): void {
                $port = (int) $this->input('smtp_port');

                if ($port === 465 && $value !== 'ssl') {
                    $fail('Port 465 uses SSL.');
                } elseif ($port !== 465 && $value === 'ssl') {
                    $fail('SSL uses port 465. For port '.$port.' choose TLS.');
                }
            }),
            'smtp_username' => $server('string', 'max:255'),
            'smtp_password' => ['exclude_unless:smtp_enabled,true', 'nullable', 'string', 'max:255'],
            'smtp_from_address' => $server('string', 'email:rfc', 'max:255'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reply_to.email' => 'Enter an email address, like orders@yourshop.co.uk.',
            'smtp_host.required' => 'Enter your email server, for example smtp.yourdomain.co.uk.',
            'smtp_port.required' => 'Choose the port.',
            'smtp_port.in' => 'Use port 587 (TLS), 465 (SSL), 25 or 2525.',
            'smtp_port.integer' => 'Use port 587 (TLS), 465 (SSL), 25 or 2525.',
            'smtp_encryption.required' => 'Choose the encryption.',
            'smtp_encryption.in' => 'Choose TLS, SSL or none.',
            'smtp_username.required' => 'Enter the username, usually your full email address.',
            'smtp_from_address.required' => 'Enter the address customer emails come from.',
            'smtp_from_address.email' => 'Enter an email address, like orders@yourshop.co.uk.',
        ];
    }

    /**
     * The settings for SaveStoreMailSettings.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            ...$this->safe()->except(['smtp_enabled', 'smtp_forget']),
            'smtp_enabled' => $this->boolean('smtp_enabled'),
            'smtp_forget' => $this->boolean('smtp_forget'),
        ];
    }
}
