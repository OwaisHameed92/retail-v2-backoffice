import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, MapPin } from 'lucide-react';
import { type InputHTMLAttributes, type ReactNode, useId } from 'react';
import { type ShopInfo } from './types';

export interface CheckoutDay {
    date: string;
    label: string;
    short: string;
    open: boolean;
    /** OSA-13: why the shop is closed that day ("Christmas Day"). */
    closedReason?: string | null;
}

export interface CheckoutSlot {
    from: string;
    to: string;
    label: string;
    available: boolean;
}

/** A numbered checkout step card. */
export function Step({ n, title, children, id }: { n: number; title: string; children: ReactNode; id?: string }) {
    return (
        <section aria-labelledby={id} className="flex flex-col gap-4 rounded-[28px] bg-white p-5 sm:gap-5 sm:p-7">
            <h2
                id={id}
                tabIndex={-1}
                className="font-display flex items-center gap-3 text-[22px] font-extrabold tracking-[-0.03em] outline-none sm:text-[26px]"
            >
                <span
                    aria-hidden="true"
                    className="bg-ink text-lime flex size-9 shrink-0 items-center justify-center rounded-full font-sans text-base"
                >
                    {n}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

/** The collection shop: a choice when the store has more than one (prices and slots follow it), else its address. */
export function ShopChoice({ shop, shops, chooseUrl }: { shop: ShopInfo; shops: ShopInfo[]; chooseUrl: string }) {
    const name = useId();

    if (shops.length <= 1) {
        return (
            <div className="bg-paper flex flex-col gap-1 rounded-[18px] px-5 py-4">
                <span className="font-bold">{shop.name}</span>
                {shop.address && <span className="text-body text-sm">{shop.address}</span>}
                <span className="text-muted text-sm">Give your order number at the counter.</span>
            </div>
        );
    }

    return (
        <fieldset className="grid gap-3 sm:grid-cols-2">
            <legend className="sr-only">Collection shop</legend>
            {shops.map((option) => {
                const selected = option.id === shop.id;

                return (
                    <label
                        key={option.id}
                        className={cn(
                            'has-[:focus-visible]:outline-ink relative flex min-h-[88px] cursor-pointer flex-col gap-1 rounded-[22px] px-5 py-4 transition has-[:focus-visible]:outline-3',
                            selected ? 'bg-ink text-white' : 'bg-paper text-ink hover:bg-line',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.id}
                            checked={selected}
                            onChange={() => router.post(chooseUrl, { shopId: option.id }, { preserveScroll: true, preserveState: true })}
                            className="sr-only"
                        />
                        <span className="font-display flex items-center justify-between gap-2 text-xl font-extrabold">
                            {option.name}
                            {selected && <Check aria-hidden="true" className="text-lime size-5" />}
                        </span>
                        {option.address && (
                            <span className={cn('flex items-start gap-1.5 text-sm', selected ? 'text-white/85' : 'text-body')}>
                                <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                {option.address}
                            </span>
                        )}
                    </label>
                );
            })}
        </fieldset>
    );
}

/** Day tabs and the slots of the chosen day (full slots shown, not choosable). */
export function SlotPicker({
    days,
    day,
    slots,
    value,
    onDay,
    onSlot,
    error,
    word = 'collection',
}: {
    days: CheckoutDay[];
    day: string | null;
    slots: CheckoutSlot[];
    value: string;
    onDay: (date: string) => void;
    onSlot: (from: string) => void;
    error?: string;
    /** "collection" or "delivery" (OS-9). */
    word?: string;
}) {
    const dayGroup = useId();
    const slotGroup = useId();

    return (
        <div className="flex flex-col gap-4">
            <div
                id="checkout-days"
                tabIndex={-1}
                role="radiogroup"
                aria-labelledby={dayGroup}
                className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none]"
            >
                <span id={dayGroup} className="sr-only">
                    {word === 'delivery' ? 'Delivery day' : 'Collection day'}
                </span>
                {days.map((d) => {
                    const selected = d.date === day;

                    return (
                        <button
                            key={d.date}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            disabled={!d.open}
                            onClick={() => onDay(d.date)}
                            className={cn(
                                'flex min-h-[60px] min-w-[104px] shrink-0 flex-col items-center justify-center rounded-[18px] px-4 transition disabled:cursor-not-allowed disabled:opacity-45',
                                selected ? 'bg-ink text-white' : 'bg-paper text-ink hover:bg-line',
                            )}
                        >
                            <span className="font-extrabold">{d.label}</span>
                            <span className="text-xs">{d.open ? d.short : (d.closedReason ?? 'Closed')}</span>
                        </button>
                    );
                })}
            </div>

            {slots.length === 0 ? (
                <p className="bg-paper text-body rounded-[18px] px-5 py-4">No {word} times left on this day. Try another day.</p>
            ) : (
                <div
                    role="radiogroup"
                    aria-labelledby={slotGroup}
                    className="grid grid-cols-2 gap-2.5 sm:grid-cols-[repeat(auto-fill,minmax(150px,1fr))]"
                >
                    <span id={slotGroup} className="sr-only">
                        {word === 'delivery' ? 'Delivery time' : 'Collection time'}
                    </span>
                    {slots.map((slot) => {
                        const selected = slot.from === value;

                        return (
                            <button
                                key={slot.from}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                disabled={!slot.available}
                                onClick={() => onSlot(slot.from)}
                                className={cn(
                                    'disabled:bg-paper disabled:text-muted flex min-h-[54px] flex-col items-center justify-center rounded-full px-3 font-bold tabular-nums transition disabled:cursor-not-allowed disabled:line-through',
                                    selected ? 'bg-brand text-on-brand' : 'text-ink ring-line hover:ring-ink bg-white ring-1',
                                )}
                            >
                                {slot.label}
                                {!slot.available && <span className="sr-only"> (full)</span>}
                            </button>
                        );
                    })}
                </div>
            )}
            {error && (
                <p role="alert" className="text-sale text-sm font-semibold">
                    {error}
                </p>
            )}
        </div>
    );
}

/** A labelled text field with its error. */
export function Field({ label, error, id, ...input }: { label: string; error?: string; id: string } & InputHTMLAttributes<HTMLInputElement>) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-[13px] font-semibold">
                {label}
            </label>
            <input
                id={id}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className={cn(
                    'bg-paper text-ink focus:ring-ink min-h-[52px] rounded-[14px] border-0 px-4 text-base outline-none focus:ring-2',
                    error && 'ring-sale ring-2',
                )}
                {...input}
            />
            {error && (
                <p id={`${id}-error`} className="text-sale text-sm font-semibold">
                    {error}
                </p>
            )}
        </div>
    );
}
