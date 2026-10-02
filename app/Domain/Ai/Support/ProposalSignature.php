<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\Models\AiPendingAction;

/**
 * The confirmation token of a proposed change: an HMAC (APP_KEY) over the company, the proposer (user or admin), the
 * tool, the exact input and the expiry. ToolExecutor signs a proposal when it is stored; ConfirmAiAction refuses to run
 * one whose signature does not match, so a confirmation can only ever run the change that was previewed, for the
 * person and business it was previewed to, before it expires, and only once (the row is claimed under a lock).
 */
final class ProposalSignature
{
    public static function sign(AiPendingAction $action): string
    {
        return hash_hmac('sha256', self::payload($action), self::key());
    }

    public static function matches(AiPendingAction $action): bool
    {
        $signature = $action->signature;

        return is_string($signature) && $signature !== '' && hash_equals(self::sign($action), $signature);
    }

    private static function payload(AiPendingAction $action): string
    {
        return (string) json_encode([
            'company' => $action->company_id,
            'user' => $action->user_id === null ? null : (int) $action->user_id,
            'admin' => $action->admin_id,
            'tool' => $action->tool,
            // Signed as stored: a JSON round trip first (1.0 is stored as 1), then keys sorted.
            'input' => self::canonical(json_decode((string) json_encode($action->input), true)),
            'expires' => $action->expires_at->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Key order is not kept by every database's JSON column (MySQL sorts keys), so sign a sorted copy.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function key(): string
    {
        return 'ai-proposal|'.(string) config('app.key');
    }
}
