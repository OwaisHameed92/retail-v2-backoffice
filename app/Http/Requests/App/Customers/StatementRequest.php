<?php

namespace App\Http\Requests\App\Customers;

use App\Domain\Customers\Queries\CustomerStatement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The date range of a customer statement (module 4.4): London days "Y-m-d", at most CustomerStatement::MAX_DAYS
 * long. Left out = this month so far. The route checks the ability.
 */
class StatementRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d', 'after:1999-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before:2100-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end date must be on or after the start date.'];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->period();

            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= CustomerStatement::MAX_DAYS) {
                $validator->errors()->add('to', 'A statement covers at most two years. Choose a shorter range.');
            }
        }];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function period(): array
    {
        return CustomerStatement::period($this->string('from')->toString() ?: null, $this->string('to')->toString() ?: null);
    }
}
