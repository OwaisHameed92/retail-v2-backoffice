<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * A client joins the walk-in queue from their phone (W3a, salon pack README §4.3, §6): a join row with our ULID as
 * `joinId`, which the shop's main till collects (`GET sync/salon/queue-joins`), adds to its queue exactly as the desk
 * would, and answers. Checked like the desk's Add (first name ≤ 60, phone digits / + ( ) - / spaces ≤ 30, note ≤ 200,
 * at most 10 services, each on the menu, the stylist one the page lists), so most clients never see a refusal.
 *
 * Refused while the queue does not take phone joins (till away, phone join off, queue closed): "ask at the desk".
 * The form's own key makes a double tap one join.
 */
final class JoinSalonQueue
{
    public const NOT_TAKING = 'The queue is not taking phone joins just now — please ask at the desk.';

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array{firstName: string, phone: string, services: list<string>, stylist: string|null, note: string, src: string, clientKey: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(SalonOnlineShop $shop, array $data): SalonQueueJoin
    {
        return $this->tenancy->runAs($shop->company()->firstOrFail(), function () use ($shop, $data): SalonQueueJoin {
            $existing = $this->find($shop, $data['clientKey']);

            if ($existing !== null) {
                return $existing;
            }

            if (! SalonShopStatus::queueOpen($shop, CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['firstName' => self::NOT_TAKING]);
            }

            $menu = self::menuIds((array) $shop->page);
            $stylist = $data['stylist'] === null || $data['stylist'] === 'any' ? null : $data['stylist'];

            foreach ($data['services'] as $id) {
                if (! isset($menu['services'][$id])) {
                    throw ValidationException::withMessages(['services' => 'Choose services from the list.']);
                }
            }

            if ($stylist !== null && ! isset($menu['stylists'][$stylist])) {
                throw ValidationException::withMessages(['stylist' => 'Choose a stylist from the list, or anyone.']);
            }

            try {
                return SalonQueueJoin::query()->create([
                    'branch_id' => $shop->branch_id,
                    'client_key' => $data['clientKey'],
                    'first_name' => trim($data['firstName']),
                    'phone' => trim($data['phone']),
                    'service_ids' => array_values(array_unique($data['services'])),
                    'stylist_user_id' => $stylist,
                    'note' => trim($data['note']),
                    'src' => mb_substr($data['src'], 0, 30),
                    'joined_at' => CarbonImmutable::now('UTC'),
                ]);
            } catch (QueryException $e) {
                return $this->find($shop, $data['clientKey']) ?? throw $e;
            }
        });
    }

    private function find(SalonOnlineShop $shop, string $clientKey): ?SalonQueueJoin
    {
        return SalonQueueJoin::query()->where('branch_id', $shop->branch_id)->where('client_key', $clientKey)->first();
    }

    /**
     * What a phone joiner may pick: every bookable option (and service) id, and the stylists the page lists.
     *
     * @param  array<string, mixed>  $page
     * @return array{services: array<string, true>, stylists: array<string, true>}
     */
    public static function menuIds(array $page): array
    {
        $services = [];

        foreach ($page['services'] ?? [] as $service) {
            $services[$service['productId']] = true;

            foreach ($service['options'] as $option) {
                $services[$option['productId']] = true;
            }
        }

        return ['services' => $services, 'stylists' => array_fill_keys(array_column($page['stylists'] ?? [], 'userId'), true)];
    }
}
