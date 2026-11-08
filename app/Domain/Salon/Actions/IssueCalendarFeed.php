<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Calendar\FeedToken;
use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Salon\Support\SalonShops;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\SalonStaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a stylist's calendar feed on, or gives it a new address (salon W3b). A new random token every time: the old
 * address stops working at once. Returns the plain token for the one-time reply; only its hash and last 4 characters
 * are kept. The person must be a stylist of that salon shop on the till (a SalonStaffProfile row), within the
 * current business (tenant scope).
 */
final class IssueCalendarFeed
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return array{feed: SalonCalendarFeed, token: string, regenerated: bool}
     */
    public function handle(Branch $branch, string $userId, ?User $by = null): array
    {
        if (! SalonShops::isSalonBranch($branch->company_id, $branch->id)
            || ! SalonStaffProfile::query()->where('branch_id', $branch->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user' => 'This person is not a stylist of that shop on the till.']);
        }

        $token = FeedToken::generate();

        return DB::transaction(function () use ($branch, $userId, $by, $token): array {
            $feed = SalonCalendarFeed::query()->lockForUpdate()->firstOrNew(['branch_id' => $branch->id, 'user_id' => $userId]);
            $regenerated = $feed->exists && $feed->isOn();

            $feed->fill([
                'token_hash' => FeedToken::hash($token),
                'token_last4' => FeedToken::last4($token),
                'enabled' => true,
                'issued_at' => CarbonImmutable::now(),
                'revoked_at' => null,
                'last_fetched_at' => null,
                'created_by_user_id' => $by?->id,
            ])->save();

            $this->audit->handle($regenerated ? 'salon.calendar_feed_regenerated' : 'salon.calendar_feed_enabled', $feed, null, null, [
                'branch_id' => $branch->id,
                'stylist_user_id' => $userId,
            ], actor: $by);

            return ['feed' => $feed, 'token' => $token, 'regenerated' => $regenerated];
        });
    }
}
