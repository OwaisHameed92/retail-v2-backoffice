<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\AlertNotification;

/**
 * Marks the signed-in user's bell entries read (module 7.8): one, or all when no id is given. Runs inside the company
 * scope, so another business's or another user's entries are never touched. Returns how many changed.
 */
class MarkNotificationsRead
{
    public function handle(int $userId, ?string $id = null): int
    {
        return AlertNotification::query()->where('user_id', $userId)->whereNull('read_at')
            ->when($id !== null, fn ($q) => $q->whereKey($id))
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
