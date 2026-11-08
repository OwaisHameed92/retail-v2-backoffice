import { cn } from '@/lib/utils';
import { Users } from 'lucide-react';
import { minutes, money } from './api';
import { type BasketLine, type Stylist } from './types';

/** Stylists who do every chosen option, in the page's order. */
export function ableStylists(stylists: Stylist[], basket: BasketLine[]): Stylist[] {
    return stylists.filter((s) => basket.every((line) => line.option.byStylist.some((b) => b.userId === s.userId)));
}

/** The stylists a choice covers: null = everyone (any), a group's members, or the one stylist. */
export function choiceIds(choice: string, able: Stylist[]): string[] | null {
    if (choice === 'any') {
        return null;
    }

    return choice === 'female' || choice === 'male' ? able.filter((s) => s.group === choice).map((s) => s.userId) : [choice];
}

/** The basket's price and minutes for the stylists of a choice (their own prices), the lowest across them. */
export function basketFor(
    basket: BasketLine[],
    addOnTotal: { price: number; minutes: number },
    userIds: string[] | null,
): { price: number; minutes: number; from: boolean } {
    let price = addOnTotal.price;
    let total = addOnTotal.minutes;
    let from = false;

    for (const line of basket) {
        const rows = userIds === null ? line.option.byStylist : line.option.byStylist.filter((b) => userIds.includes(b.userId));
        const prices = rows.map((r) => Number(r.price));
        price += prices.length > 0 ? Math.min(...prices) : Number(line.option.price);
        total += rows.length > 0 ? Math.min(...rows.map((r) => r.durationMin)) : line.option.durationMin;
        from = from || line.service.priceIsFrom || (prices.length > 1 && Math.max(...prices) !== Math.min(...prices));
    }

    return { price, minutes: total, from };
}

/**
 * Who the client would like (W3a, README §4.1): "Any stylist" (the till picks), "Any female / male stylist" when the
 * shop's team has those groups, or one of the stylists who do everything chosen, with their own price and minutes.
 */
export function StylistPicker({
    stylists,
    basket,
    addOnTotal,
    value,
    onChange,
}: {
    stylists: Stylist[];
    basket: BasketLine[];
    addOnTotal: { price: number; minutes: number };
    value: string;
    onChange: (value: string) => void;
}) {
    const able = ableStylists(stylists, basket);
    const priceOf = (ids: string[] | null) => {
        const sum = basketFor(basket, addOnTotal, ids);

        return `${sum.from ? 'from ' : ''}${money(sum.price)}`;
    };
    const choices: { value: string; title: string; hint: string; price: string }[] = [
        { value: 'any', title: 'Any stylist', hint: 'The shop picks who is free', price: priceOf(null) },
    ];

    for (const [group, title] of [
        ['female', 'Any female stylist'],
        ['male', 'Any male stylist'],
    ] as const) {
        if (able.some((s) => s.group === group) && able.some((s) => s.group !== group)) {
            choices.push({ value: group, title, hint: 'The shop picks who is free', price: priceOf(choiceIds(group, able)) });
        }
    }

    for (const stylist of able) {
        const own = basketFor(basket, addOnTotal, [stylist.userId]);
        choices.push({
            value: stylist.userId,
            title: stylist.firstName,
            hint: `${stylist.levelName ?? ''}${stylist.levelName ? ' · ' : ''}${minutes(own.minutes)}`,
            price: money(own.price),
        });
    }

    return (
        <div role="radiogroup" aria-label="Stylist" className="grid gap-2 sm:grid-cols-2">
            {choices.map((choice) => (
                <button
                    key={choice.value}
                    type="button"
                    role="radio"
                    aria-checked={value === choice.value}
                    onClick={() => onChange(choice.value)}
                    className={cn(
                        'focus-visible:ring-ring/30 flex items-center gap-3 rounded-lg border p-3 text-left outline-none focus-visible:ring-[3px]',
                        value === choice.value ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/60',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className="bg-muted text-muted-foreground grid size-9 shrink-0 place-items-center rounded-full text-sm font-semibold"
                    >
                        {choice.value === 'any' || choice.value === 'female' || choice.value === 'male' ? (
                            <Users className="size-4" />
                        ) : (
                            choice.title.charAt(0)
                        )}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block font-medium">{choice.title}</span>
                        <span className="text-muted-foreground block truncate text-xs">{choice.hint}</span>
                    </span>
                    <span className="tabular-nums">{choice.price}</span>
                </button>
            ))}
        </div>
    );
}
