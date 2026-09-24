<?php

namespace App\Domain\Ai\Data;

use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;

/**
 * The result of one user turn with the assistant.
 */
final readonly class AssistantReply
{
    /**
     * @param  list<AiPendingAction>  $proposals  Changes waiting for the user to confirm.
     */
    public function __construct(
        public AiConversation $conversation,
        public string $text,
        public array $proposals,
        public AiTokenUsage $usage,
        public string $costGbp,
        public int $steps,
        public ?string $stopReason,
        public bool $stoppedEarly = false,
        public bool $refused = false,
    ) {}
}
