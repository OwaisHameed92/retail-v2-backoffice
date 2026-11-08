<?php

namespace App\Http\Requests\App;

use App\Domain\Workshop\Support\OAuthClient;
use App\Http\Controllers\App\WorkshopAuthorizeController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * W5: the shop owner's answer on `/oauth/authorize`. The client and redirect URI are checked again (never trusted from
 * the page), the scope must be ours, and approving needs a shop.
 */
class WorkshopAuthorizeRequest extends FormRequest
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
            'client_id' => ['required', 'string', 'max:200'],
            'redirect_uri' => ['required', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:500'],
            'decision' => ['required', 'in:approve,deny'],
            'branch_id' => ['required_if:decision,approve', 'nullable', 'string', 'max:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['branch_id.required_if' => 'Choose the shop BikeBook may use.'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $error = WorkshopAuthorizeController::requestError($this->all());

            if ($error !== null) {
                $validator->errors()->add('request', $error);
            } elseif (OAuthClient::scopes($this->input('scope')) === null) {
                $validator->errors()->add('scope', 'BikeBook asked for something this portal does not offer.');
            }
        }];
    }
}
