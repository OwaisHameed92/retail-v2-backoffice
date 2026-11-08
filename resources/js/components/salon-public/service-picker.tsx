import { cn } from '@/lib/utils';
import { Check, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { minutes, money } from './api';
import { SalonTick } from './fields';
import { type BasketLine, type Service, type ServiceOption } from './types';

const GROUP_LABELS: Record<string, string> = { men: 'Men', women: 'Women', kids: 'Kids' };

/** "£18.00" or "from £18.00" (a "from" service, or stylists who charge differently). */
export function optionPrice(service: Service, option: ServiceOption): string {
    const prices = option.byStylist.map((s) => Number(s.price));
    const low = prices.length > 0 ? Math.min(...prices) : Number(option.price);
    const varies = prices.length > 0 && Math.max(...prices) !== low;

    return `${service.priceIsFrom || varies ? 'from ' : ''}${money(low)}`;
}

/**
 * The menu as the till published it (W3a, README §4.1): services by category, each with its options (hair length or
 * type) and, once chosen, its add-ons; a filter for who it is for (men, women, kids; "everyone" services always show).
 * Several services may go in one booking, one option each.
 */
export function ServicePicker({
    services,
    groups,
    basket,
    addOns,
    onToggle,
    onAddOn,
}: {
    services: Service[];
    groups: string[];
    basket: BasketLine[];
    addOns: string[];
    onToggle: (service: Service, option: ServiceOption) => void;
    onAddOn: (productId: string, on: boolean) => void;
}) {
    const [group, setGroup] = useState<string>('all');
    const shown = services.filter((s) => group === 'all' || s.clientGroup === group || s.clientGroup === 'everyone' || s.clientGroup === '');
    const categories = useMemo(() => Array.from(new Set(shown.map((s) => s.categoryName || 'Services'))), [shown]);

    return (
        <div className="space-y-5">
            {groups.length > 1 && (
                <div role="group" aria-label="Who is it for" className="flex flex-wrap gap-2">
                    {['all', ...groups].map((g) => (
                        <button
                            key={g}
                            type="button"
                            aria-pressed={group === g}
                            onClick={() => setGroup(g)}
                            className={cn(
                                'focus-visible:ring-ring/30 rounded-full border px-3 py-1 text-xs font-medium outline-none focus-visible:ring-[3px]',
                                group === g ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:border-primary/60',
                            )}
                        >
                            {g === 'all' ? 'Everyone' : (GROUP_LABELS[g] ?? g)}
                        </button>
                    ))}
                </div>
            )}

            {categories.map((category) => (
                <div key={category} className="space-y-2">
                    <h3 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{category}</h3>
                    <ul className="space-y-2">
                        {shown
                            .filter((s) => (s.categoryName || 'Services') === category)
                            .map((service) => {
                                const chosen = basket.find((line) => line.service.productId === service.productId);

                                return (
                                    <li
                                        key={service.productId}
                                        className={cn('rounded-lg border p-3', chosen ? 'border-primary bg-primary/5' : 'border-border')}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="font-medium">{service.name}</p>
                                                {service.description !== '' && (
                                                    <p className="text-muted-foreground mt-0.5 text-xs">{service.description}</p>
                                                )}
                                                {service.needsPatchTest && (
                                                    <p className="text-muted-foreground mt-0.5 text-xs">Needs a patch test first.</p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {service.options.map((option) => {
                                                const picked = chosen?.option.productId === option.productId;

                                                return (
                                                    <button
                                                        key={option.productId}
                                                        type="button"
                                                        aria-pressed={picked}
                                                        onClick={() => onToggle(service, option)}
                                                        className={cn(
                                                            'focus-visible:ring-ring/30 inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-left outline-none focus-visible:ring-[3px]',
                                                            picked
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'border-border bg-card hover:border-primary/60',
                                                        )}
                                                    >
                                                        {picked ? (
                                                            <Check className="size-4" aria-hidden="true" />
                                                        ) : (
                                                            <Plus className="size-4" aria-hidden="true" />
                                                        )}
                                                        <span>
                                                            {option.label !== '' && <span className="font-medium">{option.label} · </span>}
                                                            <span className="tabular-nums">{optionPrice(service, option)}</span>
                                                            <span className={cn('ml-1 text-xs', picked ? 'opacity-80' : 'text-muted-foreground')}>
                                                                {minutes(option.durationMin)}
                                                            </span>
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        {chosen && service.addOns.length > 0 && (
                                            <div className="mt-3 space-y-2 border-t pt-3">
                                                <p className="text-muted-foreground text-xs">Add to it</p>
                                                {service.addOns.map((addOn) => (
                                                    <SalonTick
                                                        key={addOn.productId}
                                                        id={`addon-${addOn.productId}`}
                                                        checked={addOns.includes(addOn.productId)}
                                                        onChange={(on) => onAddOn(addOn.productId, on)}
                                                    >
                                                        {addOn.name}{' '}
                                                        <span className="text-muted-foreground tabular-nums">
                                                            +{money(addOn.price)}, +{minutes(addOn.extraMin)}
                                                        </span>
                                                    </SalonTick>
                                                ))}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                    </ul>
                </div>
            ))}

            {shown.length === 0 && <p className="text-muted-foreground">No services to book online for this choice.</p>}
        </div>
    );
}
