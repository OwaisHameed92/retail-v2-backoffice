<?php

namespace App\Http\Requests\App;

use App\Domain\Billing\Actions\SendBillingRequest;
use App\Domain\Billing\Enums\BillingRequestKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Cancel my subscription" / "Change my bank account" (module 4.10). Route: `company.can:billing.manage` (owner). */
class BillingRequestRequest extends FormRequest
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
        $cancel = $this->input('kind') === BillingRequestKind::Cancel->value;

        return [
            'kind' => ['required', Rule::enum(BillingRequestKind::class)],
            // A reason helps us, but only a cancellation asks for one (and a tick to confirm it).
            'message' => [$cancel ? 'required' : 'nullable', 'string', 'max:'.SendBillingRequest::MAX_MESSAGE],
            'phone' => ['nullable', 'string', 'max:30'],
            'confirm' => $cancel ? ['required', 'accepted'] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Tell us briefly why you want to cancel, so we can help.',
            'confirm.required' => 'Tick the box to confirm you want us to cancel.',
            'confirm.accepted' => 'Tick the box to confirm you want us to cancel.',
        ];
    }

    public function kind(): BillingRequestKind
    {
        return BillingRequestKind::from((string) $this->validated('kind'));
    }
}
