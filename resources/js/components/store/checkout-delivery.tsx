import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, CircleAlert, Loader2, MapPin, Store, Truck } from 'lucide-react';
import { type ReactNode, useId, useState } from 'react';
import { Field } from './checkout-parts';
import { money } from './format';

/** OS-9: the delivery quote for the postcode chosen (App\Domain\OnlineStore\Data\DeliveryQuote). */
export interface DeliveryQuote {
    offered: boolean;
    postcode: string | null;
    covered: boolean;
    fee: string;
    freeOver: string | null;
    minimum: string | null;
    shortBy: string | null;
    problem: string | null;
}

export type Fulfilment = 'collection' | 'delivery';

/** Click & collect or delivery: two big choices. Changing it reloads the slots and totals for that method. */
export function MethodChoice({
    method,
    delivery,
    url,
    shopName,
}: {
    method: Fulfilment;
    delivery: DeliveryQuote | null;
    url: string;
    shopName: string;
}) {
    const name = useId();
    const choose = (value: Fulfilment) =>
        router.post(
            url,
            { fulfilment: value, postcode: delivery?.postcode ?? '' },
            { preserveScroll: true, preserveState: true, only: ['method', 'delivery', 'days', 'day', 'slots', 'payments', 'totals', 'errors'] },
        );
    const deliveryHint = !delivery
        ? ''
        : Number(delivery.fee) === 0 && delivery.covered
          ? 'Free delivery'
          : delivery.freeOver
            ? `${money(delivery.fee)}, free over ${money(delivery.freeOver)}`
            : `From ${money(delivery.fee)}`;

    return (
        <fieldset className="grid gap-3 sm:grid-cols-2">
            <legend className="sr-only">How you get your order</legend>
            <Option name={name} selected={method === 'collection'} onSelect={() => choose('collection')} icon={<Store className="size-5" />}>
                <span className="font-display text-xl font-extrabold">Click &amp; collect</span>
                <span className="text-sm">Free, from {shopName}</span>
            </Option>
            {delivery && (
                <Option name={name} selected={method === 'delivery'} onSelect={() => choose('delivery')} icon={<Truck className="size-5" />}>
                    <span className="font-display text-xl font-extrabold">Delivery</span>
                    <span className="text-sm">
                        {deliveryHint}
                        {delivery.minimum ? ` · minimum ${money(delivery.minimum)}` : ''}
                    </span>
                </Option>
            )}
        </fieldset>
    );
}

function Option({
    name,
    selected,
    onSelect,
    icon,
    children,
}: {
    name: string;
    selected: boolean;
    onSelect: () => void;
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <label
            className={cn(
                'has-[:focus-visible]:outline-ink relative flex min-h-[88px] cursor-pointer items-center gap-4 rounded-[22px] px-5 py-4 transition has-[:focus-visible]:outline-3',
                selected ? 'bg-ink text-white' : 'bg-paper text-ink hover:bg-line',
            )}
        >
            <input type="radio" name={name} checked={selected} onChange={onSelect} className="sr-only" />
            <span
                aria-hidden="true"
                className={cn('flex size-11 shrink-0 items-center justify-center rounded-full', selected ? 'bg-lime text-ink' : 'bg-white')}
            >
                {icon}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">{children}</span>
            {selected && <Check aria-hidden="true" className="text-lime size-5 shrink-0" />}
        </label>
    );
}

/** The postcode check: the server says at once whether the shop delivers there, the fee and the minimum. */
export function PostcodeCheck({
    delivery,
    url,
    error,
    label,
    example,
}: {
    delivery: DeliveryQuote;
    url: string;
    error?: string;
    label: string;
    example: string;
}) {
    const [postcode, setPostcode] = useState(delivery.postcode ?? '');
    const [busy, setBusy] = useState(false);
    const check = () =>
        router.post(
            url,
            { fulfilment: 'delivery', postcode },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['delivery', 'days', 'day', 'slots', 'payments', 'totals', 'errors'],
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    const ok = delivery.covered && !delivery.problem;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div className="flex-1">
                    <Field
                        id="delivery-postcode-check"
                        label={`Your ${label.toLowerCase()}`}
                        autoComplete="postal-code"
                        placeholder={example}
                        value={postcode}
                        onChange={(e) => setPostcode(e.target.value.toUpperCase())}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                check();
                            }
                        }}
                        error={error}
                    />
                </div>
                <button
                    type="button"
                    onClick={check}
                    disabled={busy || postcode.trim() === ''}
                    className="bg-ink inline-flex min-h-[52px] items-center justify-center gap-2 rounded-[14px] px-6 font-bold text-white disabled:opacity-50"
                >
                    {busy ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : <MapPin aria-hidden="true" className="size-4" />}
                    Check
                </button>
            </div>
            <div role="status" aria-live="polite">
                {delivery.postcode && ok && (
                    <p className="bg-lime text-ink flex items-start gap-2 rounded-[18px] px-5 py-3 font-semibold">
                        <Check aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                        We deliver to {delivery.postcode}. Delivery {Number(delivery.fee) === 0 ? 'is free' : `costs ${money(delivery.fee)}`}
                        {delivery.freeOver && Number(delivery.fee) > 0 ? `, free over ${money(delivery.freeOver)}` : ''}.
                    </p>
                )}
                {delivery.problem && (delivery.postcode || delivery.shortBy) && (
                    <p className="ring-sale flex items-start gap-2 rounded-[18px] bg-white px-5 py-3 font-semibold ring-2">
                        <CircleAlert aria-hidden="true" className="text-sale mt-0.5 size-4 shrink-0" />
                        {delivery.problem}
                    </p>
                )}
            </div>
        </div>
    );
}

export interface AddressValues {
    deliveryLine1: string;
    deliveryLine2: string;
    deliveryTown: string;
    deliveryNotes: string;
}

/** The delivery address (the postcode is the one checked above). */
export function AddressFields({
    values,
    errors,
    onChange,
}: {
    values: AddressValues;
    errors: Partial<Record<keyof AddressValues | 'deliveryPostcode', string>>;
    onChange: (key: keyof AddressValues, value: string) => void;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <Field
                    id="delivery-line1"
                    label="Address line 1"
                    autoComplete="address-line1"
                    value={values.deliveryLine1}
                    onChange={(e) => onChange('deliveryLine1', e.target.value)}
                    error={errors.deliveryLine1}
                    required
                />
            </div>
            <Field
                id="delivery-line2"
                label="Address line 2 (optional)"
                autoComplete="address-line2"
                value={values.deliveryLine2}
                onChange={(e) => onChange('deliveryLine2', e.target.value)}
                error={errors.deliveryLine2}
            />
            <Field
                id="delivery-town"
                label="Town or city"
                autoComplete="address-level2"
                value={values.deliveryTown}
                onChange={(e) => onChange('deliveryTown', e.target.value)}
                error={errors.deliveryTown}
                required
            />
            <div className="sm:col-span-2">
                <Field
                    id="delivery-notes"
                    label="Instructions for the driver (optional)"
                    maxLength={255}
                    value={values.deliveryNotes}
                    onChange={(e) => onChange('deliveryNotes', e.target.value)}
                    error={errors.deliveryNotes}
                />
            </div>
        </div>
    );
}
