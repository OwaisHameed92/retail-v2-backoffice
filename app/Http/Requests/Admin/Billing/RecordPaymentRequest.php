<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $allocations = $this->input('allocations');

        $this->merge([
            'amount' => self::cleanMoney($this->input('amount')),
            'allocations' => is_array($allocations) ? array_map(fn (mixed $value) => self::cleanMoney($value), $allocations) : $allocations,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::manual()))],
            'amount' => ['required', 'string', 'regex:'.self::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:'.BillingDates::today()->format('Y-m-d')],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocation' => ['required', Rule::in(['auto', 'manual'])],
            'allocations' => ['nullable', 'array', 'max:50'],
            'allocations.*' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.required' => 'Choose how it was paid.',
            'method.in' => 'Choose cash, bank transfer or other.',
            'amount.required' => 'Enter the amount received.',
            'amount.regex' => self::MONEY_MESSAGE,
            'amount.not_regex' => 'Enter an amount above £0.00.',
            'received_on.required' => 'Enter the date the money was received.',
            'received_on.before_or_equal' => 'The date cannot be in the future.',
            'allocations.*.regex' => self::MONEY_MESSAGE,
        ];
    }

    public function toNewPayment(): NewPayment
    {
        $day = BillingDates::date((string) $this->validated('received_on'));
        $today = BillingDates::today();
        // Today: now. An earlier day: midday London, so the date shows the same everywhere.
        $receivedAt = $day->equalTo($today) ? CarbonImmutable::now() : CarbonImmutable::parse($day->format('Y-m-d').' 12:00:00', Country::zone())->utc();

        $allocations = null;

        if ($this->validated('allocation') === 'manual') {
            /** @var array<string, string|null> $chosen */
            $chosen = $this->validated('allocations') ?? [];
            $allocations = array_filter(array_map(fn (?string $value) => (string) $value, $chosen), fn (string $value) => $value !== '');
        }

        return new NewPayment(
            method: PaymentMethod::from((string) $this->validated('method')),
            amount: (string) $this->validated('amount'),
            receivedAt: $receivedAt,
            reference: $this->validated('reference'),
            notes: $this->validated('notes'),
            allocations: $allocations,
        );
    }
}
