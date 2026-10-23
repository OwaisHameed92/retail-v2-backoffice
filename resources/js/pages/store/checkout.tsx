import { type CheckoutDay, type CheckoutSlot, Field, ShopChoice, SlotPicker, Step } from '@/components/store/checkout-parts';
import { money } from '@/components/store/format';
import { OrderSummary } from '@/components/store/order-summary';
import { ProductPhoto } from '@/components/store/product-photo';
import { StoreLayout } from '@/components/store/store-layout';
import { type BasketLine, type ShopInfo, type StorePageProps } from '@/components/store/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Loader2, Store } from 'lucide-react';
import { type FormEvent } from 'react';

interface Props {
    lines: BasketLine[];
    shop: ShopInfo | null;
    shops: ShopInfo[];
    days: CheckoutDay[];
    day: string | null;
    slots: CheckoutSlot[];
    urls: { place: string; shop: string };
    honeypot: string;
}

/** Checkout, click & collect only (S1): shop, day and slot, the customer's details; pay at the shop. */
export default function StoreCheckout({ lines, shop, shops, days, day, slots, urls, honeypot }: Props) {
    const { store, basket } = usePage<StorePageProps>().props;
    const form = useForm({
        shopId: shop?.id ?? '',
        collectFrom: '',
        customerName: '',
        customerMobile: '',
        customerEmail: '',
        [honeypot]: '',
    });

    if (!store || !basket) {
        return null;
    }

    const slot = slots.find((s) => s.from === form.data.collectFrom);
    const dayLabel = days.find((d) => d.date === day);
    const general = form.errors.lines ?? form.errors.store ?? form.errors.shopId ?? form.errors[honeypot];
    const canPlace = store.takingOrders && lines.length > 0 && shop !== null;

    const chooseDay = (date: string) => {
        form.setData('collectFrom', '');
        router.get(window.location.pathname, { day: date }, { preserveState: true, preserveScroll: true, only: ['day', 'slots', 'days'] });
    };
    const place = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, shopId: shop?.id ?? '' }));
        form.post(urls.place, { preserveScroll: true });
    };

    if (lines.length === 0 || shop === null) {
        return (
            <StoreLayout title="Checkout">
                <div className="mx-auto flex max-w-xl flex-col items-start gap-4 rounded-[28px] bg-white p-8">
                    <h1 className="font-display text-4xl font-extrabold tracking-tight">Checkout</h1>
                    <p className="text-body">{shop === null ? 'This shop is not taking online orders right now.' : 'Your basket is empty.'}</p>
                    <Link href={store.urls.home} className="bg-brand text-on-brand inline-flex min-h-12 items-center rounded-full px-6 font-bold">
                        Back to the shop
                    </Link>
                </div>
            </StoreLayout>
        );
    }

    return (
        <StoreLayout title="Checkout">
            <h1 className="font-display mb-6 text-5xl leading-[0.95] font-extrabold tracking-[-0.045em] sm:text-[64px]">Checkout</h1>
            {general && (
                <div role="alert" className="ring-sale mb-6 flex items-start gap-3 rounded-3xl bg-white px-5 py-4 ring-2">
                    <CircleAlert aria-hidden="true" className="text-sale mt-0.5 size-5 shrink-0" />
                    <p className="font-semibold">{general}</p>
                </div>
            )}
            <form onSubmit={place} noValidate className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-4">
                    <Step n={1} id="step-shop" title={shops.length > 1 ? 'Where will you collect?' : 'Click & collect'}>
                        <ShopChoice shop={shop} shops={shops} chooseUrl={urls.shop} />
                    </Step>
                    <Step n={2} id="step-time" title="Choose a time">
                        <SlotPicker
                            days={days}
                            day={day}
                            slots={slots}
                            value={form.data.collectFrom}
                            onDay={chooseDay}
                            onSlot={(from) => form.setData('collectFrom', from)}
                            error={form.errors.collectFrom}
                        />
                    </Step>
                    <Step n={3} id="step-details" title="Your details">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="customer-name"
                                label="Full name"
                                autoComplete="name"
                                value={form.data.customerName}
                                onChange={(e) => form.setData('customerName', e.target.value)}
                                error={form.errors.customerName}
                                required
                            />
                            <Field
                                id="customer-mobile"
                                label="Mobile"
                                type="tel"
                                autoComplete="tel"
                                inputMode="tel"
                                value={form.data.customerMobile}
                                onChange={(e) => form.setData('customerMobile', e.target.value)}
                                error={form.errors.customerMobile}
                                required
                            />
                            <div className="sm:col-span-2">
                                <Field
                                    id="customer-email"
                                    label="Email (for your order updates)"
                                    type="email"
                                    autoComplete="email"
                                    value={form.data.customerEmail}
                                    onChange={(e) => form.setData('customerEmail', e.target.value)}
                                    error={form.errors.customerEmail}
                                    required
                                />
                            </div>
                        </div>
                        {/*
                         * Honeypot: only a bot fills it in (the server refuses the order then). People never meet it:
                         * visually hidden, aria-hidden and inert (out of the accessibility tree and the tab order),
                         * tabindex -1 for older browsers, and no autofill.
                         */}
                        <div aria-hidden="true" inert className="pointer-events-none fixed top-0 -left-[10000px] h-px w-px overflow-hidden opacity-0">
                            <label htmlFor="checkout-website">Website</label>
                            <input
                                id="checkout-website"
                                name={honeypot}
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                                data-1p-ignore
                                data-lpignore="true"
                                value={String(form.data[honeypot] ?? '')}
                                onChange={(e) => form.setData(honeypot, e.target.value)}
                            />
                        </div>
                    </Step>
                    <Step n={4} id="step-pay" title="Payment">
                        <div className="bg-ink flex items-center gap-4 rounded-[22px] p-5 text-white">
                            <span className="bg-lime text-ink flex size-11 shrink-0 items-center justify-center rounded-full">
                                <Store aria-hidden="true" className="size-5" />
                            </span>
                            <span className="flex flex-col gap-0.5">
                                <span className="font-display text-xl font-extrabold">Pay at the shop</span>
                                <span className="text-sm text-white/85">Card or cash when you collect. Nothing to pay online.</span>
                            </span>
                        </div>
                    </Step>
                </div>

                <OrderSummary title="Your order" basket={basket} className="lg:sticky lg:top-6 lg:w-[400px] lg:shrink-0">
                    <span className="bg-lime order-first -mb-1 self-start rounded-full px-3 py-1.5 text-[13px] font-bold">
                        {slot && dayLabel ? `${dayLabel.label} ${slot.label}` : 'Choose a collection time'}
                    </span>
                    <ul className="flex flex-wrap gap-2" aria-label="Items">
                        {lines.slice(0, 5).map((line) => (
                            <li key={line.product.id} className="w-14">
                                <ProductPhoto name={line.product.name} url={line.product.photoUrl} rounded="rounded-2xl" size="sm" />
                            </li>
                        ))}
                        {lines.length > 5 && (
                            <li className="bg-paper flex size-14 items-center justify-center rounded-2xl text-sm font-bold">+{lines.length - 5}</li>
                        )}
                    </ul>
                    <button
                        type="submit"
                        disabled={!canPlace || form.processing}
                        className="bg-brand text-on-brand disabled:bg-line disabled:text-muted mt-1 flex min-h-[60px] items-center justify-center gap-2 rounded-full px-6 text-[17px] font-extrabold hover:brightness-110 disabled:cursor-not-allowed"
                    >
                        {form.processing && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
                        {store.takingOrders ? `Place order · ${money(basket.total)}` : 'Not taking orders'}
                    </button>
                    <p className="text-muted text-xs leading-relaxed">
                        By placing the order you agree to collect it at {shop.name} and pay there. Change or cancel it by calling the shop.
                    </p>
                </OrderSummary>
            </form>
        </StoreLayout>
    );
}
