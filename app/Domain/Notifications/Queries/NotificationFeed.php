<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Notifications\Models\AlertNotification;

/**
 * The notifications bell (module 7.8): the signed-in user's latest entries in the current business and how many
 * are unread. Runs inside the company scope, filtered to the user.
 */
final class NotificationFeed
{
    public const LIMIT = 20;

    /**
     * @return array{unread: int, items: list<array<string, mixed>>}
     */
    public static function for(int $userId): array
    {
        $mine = fn () => AlertNotification::query()->where('user_id', $userId);

        return [
            'unread' => $mine()->whereNull('read_at')->count(),
            'items' => $mine()->latest('created_at')->latest('id')->limit(self::LIMIT)->get()->map(fn (AlertNotification $n) => [
                'id' => $n->id,
                'type' => $n->alert_type,
                'tone' => $n->tone,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read' => $n->read_at !== null,
                'createdAt' => $n->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }
}
