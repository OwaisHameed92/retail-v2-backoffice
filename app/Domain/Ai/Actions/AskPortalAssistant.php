<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Support\Portal\AssistantLinks;
use App\Domain\Ai\Support\Portal\PortalPresenter;
use App\Domain\Shared\Country\ContactRules;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * One question to the portal assistant (module 6.2): `ai.use`, the user's own conversation, personal details taken
 * out of the question, then the 6.1 tool loop (RunAssistant: gate, budget, metering, tenant-scoped tools, write tools
 * only as proposals). The report pages the tools read are stored with the answer.
 *
 *     $answer = app(AskPortalAssistant::class)->handle($user, $company, 'Top sellers this week?', null, fn ($t) => echo $t);
 */
final class AskPortalAssistant
{
    public function __construct(
        private readonly RunAssistant $assistant,
        private readonly AssistantLinks $links,
    ) {}

    /**
     * @param  Closure(string): void|null  $onText
     * @return array<string, mixed> the answer for the panel
     *
     * @throws AiAccessDenied missing `ai.use`, or someone else's conversation
     * @throws AiUnavailable not configured, not in plan, allowance used, provider down
     * @throws ValidationException empty question
     */
    public function handle(User $user, Company $company, string $question, ?string $conversationId = null, ?Closure $onText = null): array
    {
        $context = AiContext::forUser($user, $company);

        if (! $context->userCan(Ability::AiUse)) {
            throw AiAccessDenied::missingAbility();
        }

        $conversation = null;

        if ($conversationId !== null) {
            $conversation = AiConversation::query()->ownedBy($context)->find($conversationId) ?? throw AiAccessDenied::notYours();
        }

        $this->links->reset();
        $reply = $this->assistant->handle($context, self::scrub($question), $conversation, $onText);
        $links = $this->links->all();

        if ($links !== [] && ! $reply->refused) {
            AiMessage::query()->where('conversation_id', $reply->conversation->id)->where('role', 'assistant')
                ->orderByDesc('position')->first()?->forceFill(['links' => $links])->save();
        }

        return [
            'conversation' => PortalPresenter::conversation($reply->conversation),
            'answer' => $reply->refused ? RunAssistant::REFUSAL_TEXT : $reply->text,
            'refused' => $reply->refused,
            'stoppedEarly' => $reply->stoppedEarly,
            'links' => $reply->refused ? [] : $links,
            'proposals' => array_map(fn (AiPendingAction $action) => PortalPresenter::proposal($action), $reply->proposals),
        ];
    }

    /**
     * Personal details the assistant never needs: email addresses, phone numbers and card-like digit runs. Phone
     * numbers start with 0 or the profile's calling code (Pakistan plan P4: "+44" on GB as before, "+92" on PK).
     */
    public static function scrub(string $question): string
    {
        $code = ContactRules::dialCode();

        return (string) preg_replace(
            ['/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '/(?<![\w£.,])(?:\+'.$code.'\s?|0)\d(?:[\s-]?\d){8,9}(?!\d)/', '/(?<![\d.,])\d(?:[ -]?\d){12,18}(?!\d)/'],
            ['[email removed]', '[phone removed]', '[number removed]'],
            $question,
        );
    }
}
