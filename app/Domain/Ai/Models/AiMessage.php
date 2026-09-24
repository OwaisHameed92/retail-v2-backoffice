<?php

namespace App\Domain\Ai\Models;

use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a conversation, in API wire shape. Tool results are `user` messages with tool_result blocks.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int $position
 * @property string $role
 * @property list<array<string, mixed>> $content
 * @property list<array<string, mixed>>|null $tool_calls
 * @property list<array<string, mixed>>|null $tool_results
 * @property string|null $model
 * @property string|null $stop_reason
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property bool $in_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiMessage extends Model
{
    use HasPortalUlid;

    protected $table = 'ai_messages';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'position',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'model',
        'stop_reason',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'in_context',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'in_context' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'content' => 'array',
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'in_context' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isToolResult(): bool
    {
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_result') {
                return true;
            }
        }

        return false;
    }
}
