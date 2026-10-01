<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Ai\Support\Portal\AssistantLinks;

/**
 * Base of the portal assistant's read tools (module 6.2): tenant audience, read only, built on the existing report
 * queries and run by ToolExecutor inside the user's company (and one-shop limit). Each adds the page that shows the
 * same figures to AssistantLinks.
 */
abstract class PortalReadTool implements AiTool
{
    public function __construct(protected readonly AssistantLinks $links) {}

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function audience(): ToolAudience
    {
        return ToolAudience::Tenant;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties, 'additionalProperties' => false];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
