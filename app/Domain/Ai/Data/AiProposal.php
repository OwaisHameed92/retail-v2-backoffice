<?php

namespace App\Domain\Ai\Data;

/**
 * What a write tool returns instead of changing anything: the checked input and a plain-English preview.
 * ToolExecutor stores it as an AiPendingAction; ConfirmAiAction later runs the tool's execute() with `input`.
 */
final readonly class AiProposal
{
    /**
     * @param  array<string, mixed>  $input  Validated, normalised input (stored and re-validated on confirm).
     */
    public function __construct(
        public string $preview,
        public array $input,
    ) {}
}
