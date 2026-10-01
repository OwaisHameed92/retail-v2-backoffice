<?php

namespace App\Http\Controllers\App;

use App\Domain\Notifications\Actions\MarkNotificationsRead;
use App\Domain\Notifications\Queries\NotificationFeed;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top-bar notifications bell (module 7.8), JSON: the signed-in user's latest entries in the current business,
 * and marking them read. Every member.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(NotificationFeed::for($user->id));
    }

    public function read(Request $request, MarkNotificationsRead $mark): JsonResponse
    {
        $validated = $request->validate(['id' => ['nullable', 'string', 'size:26']]);

        /** @var User $user */
        $user = $request->user();
        $mark->handle($user->id, $validated['id'] ?? null);

        return response()->json(NotificationFeed::for($user->id));
    }
}
