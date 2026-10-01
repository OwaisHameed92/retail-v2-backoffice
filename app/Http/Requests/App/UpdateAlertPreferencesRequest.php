<?php

namespace App\Http\Requests\App;

use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Settings → Notifications (module 7.8): the user's own choice per alert type and, for multi-shop users, the shops.
 * Every member may save their own choices; what their role cannot see is dropped by SaveAlertPreferences.
 */
class UpdateAlertPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deliveries' => ['required', 'array'],
            'deliveries.*' => ['required', Rule::enum(AlertDelivery::class)],
            'allShops' => ['required', 'boolean'],
            'shops' => ['array', 'max:500'],
            'shops.*' => ['string', 'max:26'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('deliveries', []) as $type => $delivery) {
                $alert = AlertType::tryFrom((string) $type);

                if ($alert === null) {
                    $validator->errors()->add('deliveries', 'Unknown alert type.');
                } elseif ($delivery === AlertDelivery::Immediate->value && ! $alert->urgent()) {
                    $validator->errors()->add('deliveries.'.$type, $alert->label().' can only go in the daily digest.');
                }
            }

            if (! $this->boolean('allShops') && $this->input('shops', []) === []) {
                $validator->errors()->add('shops', 'Pick at least one shop, or choose every shop.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function deliveries(): array
    {
        return array_map('strval', (array) $this->validated('deliveries'));
    }

    /**
     * @return list<string>|null
     */
    public function shops(): ?array
    {
        return $this->boolean('allShops') ? null : array_values(array_map('strval', (array) $this->validated('shops', [])));
    }
}
