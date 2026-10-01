<?php

namespace App\Http\Requests\App;

use App\Domain\Ai\Actions\RunAssistant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question to the portal assistant (module 6.2). Route: `company.can:ai.use`, throttled. `conversationId` continues
 * one of the user's own conversations (checked by AskPortalAssistant).
 */
class AskAssistantRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:'.RunAssistant::MAX_QUESTION_CHARS, 'not_regex:/^\s*$/'],
            'conversationId' => ['nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Type a question.',
            'question.not_regex' => 'Type a question.',
            'question.max' => 'Keep your question under '.RunAssistant::MAX_QUESTION_CHARS.' characters.',
        ];
    }
}
