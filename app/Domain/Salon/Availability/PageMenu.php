<?php

namespace App\Domain\Salon\Availability;

/**
 * The menu of a stored booking page (W3a, salon pack README §4.1): what can be booked (each service's options, with
 * every online stylist's own price and minutes), the add-ons, the stylists, and a client's basket built from them.
 *
 * A basket is the options in the client's order, then the add-ons (README §5 `servicesJson`). An option's minutes are
 * the stylist's own (`byStylist`); a stylist not listed on an option does not do it. An add-on adds its `extraMin`.
 * Its start must be on its own published grid when the till publishes one, else on the grid of the option it belongs
 * to (the till publishes times per option; it checks again on accept).
 */
final class PageMenu
{
    /** @var array<string, array{service: array<string, mixed>, option: array<string, mixed>}> */
    private array $options = [];

    /** @var array<string, array{service: array<string, mixed>, addOn: array<string, mixed>}> */
    private array $addOns = [];

    /** @var array<string, true> */
    private array $published = [];

    /**
     * @param  array<string, mixed>  $page  the stored page (BookingPageReader's shape)
     */
    public function __construct(public readonly array $page)
    {
        foreach ($page['services'] ?? [] as $service) {
            foreach ($service['options'] as $option) {
                $this->options[$option['productId']] = ['service' => $service, 'option' => $option];
            }

            foreach ($service['addOns'] as $addOn) {
                $this->addOns[$addOn['productId']] = ['service' => $service, 'addOn' => $addOn];
            }
        }

        foreach ($page['availability']['days'] ?? [] as $day) {
            foreach ($day['items'] as $item) {
                $this->published[$item['productId']] = true;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->page['rules'];
    }

    public function rule(string $key): mixed
    {
        return $this->page['rules'][$key] ?? null;
    }

    /**
     * @return array{service: array<string, mixed>, option: array<string, mixed>}|null
     */
    public function option(string $productId): ?array
    {
        return $this->options[$productId] ?? null;
    }

    /**
     * @return array{service: array<string, mixed>, addOn: array<string, mixed>}|null
     */
    public function addOn(string $productId): ?array
    {
        return $this->addOns[$productId] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>> userId => stylist
     */
    public function stylists(): array
    {
        $out = [];

        foreach ($this->page['stylists'] ?? [] as $stylist) {
            $out[$stylist['userId']] = $stylist;
        }

        return $out;
    }

    /**
     * The basket for these options and add-ons, or null when one is not on the page, an add-on comes without its
     * service, or nothing is chosen.
     *
     * @param  list<string>  $optionIds
     * @param  list<string>  $addOnIds
     * @return list<array{productId: string, kind: string, grid: string, name: string, minutes: array<string, int>|int, prices: array<string, string>|string, fallbackPrice: string, needsPatchTest: bool}>|null
     */
    public function basket(array $optionIds, array $addOnIds): ?array
    {
        $items = [];
        $optionOfService = [];

        foreach ($optionIds as $id) {
            $found = $this->option($id) ?? null;

            if ($found === null) {
                return null;
            }

            ['service' => $service, 'option' => $option] = $found;
            $optionOfService[$service['productId']] ??= $id;
            $items[] = [
                'productId' => $id, 'kind' => 'option', 'grid' => $id,
                'name' => trim($service['name'].($option['label'] !== '' ? ' – '.$option['label'] : '')),
                'minutes' => array_column($option['byStylist'], 'durationMin', 'userId'),
                'prices' => array_column($option['byStylist'], 'price', 'userId'),
                'fallbackPrice' => $option['price'],
                'needsPatchTest' => (bool) $service['needsPatchTest'],
            ];
        }

        foreach ($addOnIds as $id) {
            $found = $this->addOn($id);
            $parent = $found === null ? null : ($optionOfService[$found['service']['productId']] ?? null);

            if ($found === null || $parent === null) {
                return null;
            }

            $items[] = [
                'productId' => $id, 'kind' => 'addOn', 'grid' => isset($this->published[$id]) ? $id : $parent,
                'name' => $found['addOn']['name'], 'minutes' => $found['addOn']['extraMin'], 'prices' => $found['addOn']['price'],
                'fallbackPrice' => $found['addOn']['price'], 'needsPatchTest' => false,
            ];
        }

        return $items === [] ? null : $items;
    }

    /**
     * The basket of these product ids in this order (an appointment's services): each an option or an add-on.
     *
     * @param  list<string>  $productIds
     * @return list<array{productId: string, kind: string, grid: string, name: string, minutes: array<string, int>|int, prices: array<string, string>|string, fallbackPrice: string, needsPatchTest: bool}>|null
     */
    public function basketOf(array $productIds): ?array
    {
        $options = array_values(array_filter($productIds, fn (string $id) => $this->option($id) !== null));
        $addOns = array_values(array_filter($productIds, fn (string $id) => $this->option($id) === null));

        return $this->basket($options, $addOns);
    }

    /**
     * Minutes of a basket item for a stylist; null when the stylist does not do it.
     *
     * @param  array{minutes: array<string, int>|int}  $item
     */
    public static function minutes(array $item, string $userId): ?int
    {
        return is_int($item['minutes']) ? $item['minutes'] : ($item['minutes'][$userId] ?? null);
    }

    /**
     * Stylists who do every option of the basket, in the page's order.
     *
     * @param  list<array{minutes: array<string, int>|int}>  $basket
     * @return list<string>
     */
    public function stylistsFor(array $basket): array
    {
        return array_values(array_filter(array_keys($this->stylists()), function (string $userId) use ($basket): bool {
            foreach ($basket as $item) {
                if (self::minutes($item, $userId) === null) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Total minutes of a basket for a stylist (null when they cannot do it).
     *
     * @param  list<array{minutes: array<string, int>|int}>  $basket
     */
    public static function totalMinutes(array $basket, string $userId): ?int
    {
        $total = 0;

        foreach ($basket as $item) {
            $minutes = self::minutes($item, $userId);

            if ($minutes === null) {
                return null;
            }

            $total += $minutes;
        }

        return $total;
    }
}
