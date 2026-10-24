<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move an online order on from the board or its page (OS-4): start picking, mark ready, collected, out for delivery
 * and delivered (OS-9), not collected (OSA-21), or cancel with a reason the customer is told (or as spam, OSA-1: no
 * email). Which moves are allowed is checked by ChangeOnlineOrderStatus. The route checks
 * `orders.online`.
 */
class ChangeOnlineOrderStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                OnlineOrderStatus::Picking->value, OnlineOrderStatus::Ready->value, OnlineOrderStatus::Collected->value, OnlineOrderStatus::Cancelled->value,
                OnlineOrderStatus::OutForDelivery->value, OnlineOrderStatus::Delivered->value, OnlineOrderStatus::NotCollected->value,
            ])],
            'spam' => ['nullable', 'boolean'],
            'reason' => [
                Rule::requiredIf(fn () => $this->input('status') === OnlineOrderStatus::Cancelled->value && ! $this->boolean('spam')),
                'nullable', 'string', 'min:3', 'max:'.ChangeOnlineOrderStatus::REASON_MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.*' => 'Choose what to do with the order.',
            'reason.required' => 'Say why the order is cancelled; the customer is told.',
            'reason.min' => 'Say why the order is cancelled; the customer is told.',
            'reason.max' => 'Keep the reason to '.ChangeOnlineOrderStatus::REASON_MAX.' characters.',
        ];
    }

    public function status(): OnlineOrderStatus
    {
        return OnlineOrderStatus::from((string) $this->validated('status'));
    }
}
