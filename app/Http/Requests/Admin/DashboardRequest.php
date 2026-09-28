<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\DashboardRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /admin?range=12w|6m|1y` (module 1.9). An unknown range falls back to 12 weeks instead of failing a GET.
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (DashboardRange::tryFrom((string) $this->query('range', '')) === null) {
            $this->merge(['range' => DashboardRange::TwelveWeeks->value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['range' => ['required', Rule::enum(DashboardRange::class)]];
    }

    public function range(): DashboardRange
    {
        return DashboardRange::from((string) $this->input('range'));
    }
}
