<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Data\AiProposal;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Tenancy\Enums\Ability;

/**
 * A capability the model may call. Register the class in `config('ai.tools')`. See docs/ai.md.
 *
 * ToolExecutor, not the tool, enforces the safety rules: the tool is only offered to and run for actors with
 * `requiredAbility()` (re-checked on every call), input is validated with `rules()` and unknown keys are refused,
 * tenant tools run inside `CurrentCompany::runAs()` so every tenant query is scoped to the actor's company.
 */
interface AiTool
{
    /** snake_case, unique, stable (it is part of the cached prompt prefix). */
    public function name(): string;

    /** What the tool does and when to use it, written for the model. */
    public function description(): string;

    /**
     * JSON Schema of the input (type object, `additionalProperties: false`). Use `(object) []` for "no properties".
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Laravel validation rules for the same input. Keys not in the schema's properties are rejected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /** Tenant tools: an Ability. Admin tools: an AdminRole ability constant. */
    public function requiredAbility(): Ability|string;

    public function kind(): ToolKind;

    public function audience(): ToolAudience;

    /**
     * Read tools return data (sent to the model as JSON). Write tools return an AiProposal and change nothing
     * (or return data, e.g. "nothing to change").
     *
     * @param  array<string, mixed>  $input  Validated input.
     * @return array<string, mixed>|AiProposal
     */
    public function handle(array $input, AiContext $context): array|AiProposal;
}
