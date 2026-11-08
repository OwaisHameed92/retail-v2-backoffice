<?php

namespace App\Http\Requests\SalonPublic;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A change from a manage link (W3a, salon pack README §8): a move (stylist, day and time), a cancel, or the three
 * marketing ticks as the client left them. The link's token is checked before this runs (ManageLinkController).
 */
class ChangeAppointmentRequest extends FormRequest
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
        $move = $this->route()?->getName() === 'salon.public.manage.move';

        return [
            'stylist' => [$move ? 'required' : 'nullable', 'string', 'max:64'],
            'date' => [$move ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'time' => [$move ? 'required' : 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sms' => ['boolean'],
            'email' => ['boolean'],
            'whatsApp' => ['boolean'],
            'clientKey' => ['required', 'string', 'regex:'.BookOnlineRequest::CLIENT_KEY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date.required' => 'Choose a day.', 'time.required' => 'Choose a time.'];
    }

    /**
     * @return array{stylist: string, date: string, time: string, sms: bool, email: bool, whatsApp: bool, clientKey: string}
     */
    public function fields(): array
    {
        return [
            'stylist' => (string) $this->input('stylist', 'any'),
            'date' => (string) $this->input('date', ''),
            'time' => (string) $this->input('time', ''),
            'sms' => $this->boolean('sms'),
            'email' => $this->boolean('email'),
            'whatsApp' => $this->boolean('whatsApp'),
            'clientKey' => (string) $this->input('clientKey'),
        ];
    }
}
