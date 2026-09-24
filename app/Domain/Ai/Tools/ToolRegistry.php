<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The tools the model may be offered: `config('ai.tools')` plus any registered at runtime. Sorted by name so the
 * tool list (the start of the cached prompt prefix) is byte-identical between requests.
 *
 * Who may use a tool (checked when offering it AND again on every call):
 * - tenant tools: a tenant user whose current role has the tool's Ability; system jobs get read tools only;
 * - admin tools: an admin whose role has the tool's admin ability.
 */
#[Singleton]
final class ToolRegistry
{
    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, AiTool> keyed and sorted by name
     */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach ((array) config('ai.tools', []) as $class) {
                $tool = $this->container->make((string) $class);

                if (! $tool instanceof AiTool) {
                    throw new InvalidArgumentException("AI tool {$class} must implement ".AiTool::class.'.');
                }

                $this->add($tool);
            }
        }

        return $this->tools;
    }

    public function register(AiTool $tool): void
    {
        $this->all();
        $this->add($tool);
    }

    public function find(string $name): ?AiTool
    {
        return $this->all()[$name] ?? null;
    }

    public function allows(AiTool $tool, AiContext $context): bool
    {
        return $this->allowsWithRole($tool, $context, $context->currentRole());
    }

    /**
     * @return list<AiTool>
     */
    public function availableFor(AiContext $context): array
    {
        $role = $context->currentRole();

        return array_values(array_filter($this->all(), fn (AiTool $tool) => $this->allowsWithRole($tool, $context, $role)));
    }

    /**
     * Tool definitions in API wire shape.
     *
     * @param  list<AiTool>  $tools
     * @return list<array<string, mixed>>
     */
    public function definitions(array $tools): array
    {
        return array_map(fn (AiTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], $tools);
    }

    private function allowsWithRole(AiTool $tool, AiContext $context, ?CompanyRole $role): bool
    {
        $ability = $tool->requiredAbility();

        if ($tool->audience() === ToolAudience::Admin) {
            return $context->isAdmin() && is_string($ability) && $context->adminCan($ability);
        }

        if ($context->company === null || $context->isAdmin()) {
            return false;
        }

        if ($context->isSystem()) {
            return $tool->kind() === ToolKind::Read;
        }

        return $ability instanceof Ability && $role?->can($ability) === true;
    }

    private function add(AiTool $tool): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $tool->name()) !== 1) {
            throw new InvalidArgumentException("AI tool name '{$tool->name()}' must be snake_case.");
        }

        $this->tools[$tool->name()] = $tool;
        ksort($this->tools, SORT_STRING);
    }
}
