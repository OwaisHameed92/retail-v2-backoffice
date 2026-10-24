import { type Challenge, useChallengeAnswer } from '@/components/store/checkout-challenge';
import { AddressFields, type DeliveryQuote, type Fulfilment, MethodChoice, PostcodeCheck } from '@/components/store/checkout-delivery';
import { CheckoutExtras, Honeypot } from '@/components/store/checkout-extras';
import { type CheckoutDay, type CheckoutSlot, Field, ShopChoice, SlotPicker, Step } from '@/components/store/checkout-parts';
import { PaymentChoice, type PaymentOption } from '@/components/store/checkout-payment';
import { money } from '@/components/store/format';
import { OrderSummary } from '@/components/store/order-summary';
import { ProductPhoto } from '@/components/store/product-photo';
import { StoreLayout } from '@/components/store/store-layout';
import { type BasketLine, type ShopInfo, type StorePageProps } from '@/components/store/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Loader2 } from 'lucide-react';
import { type FormEvent, useEffect } from 'react';

interface Props {
    lines: BasketLine[];
    shop: ShopInfo | null;
    shops: ShopInfo[];
    method: Fulfilment;
    delivery: DeliveryQuote | null;
    days: CheckoutDay[];
    day: string | null;
    slots: CheckoutSlot[];
    payments: PaymentOption[];
    totals: { items: string; delivery: string; grand: string };
    address: { postcodeLabel: string; postcodeExample: string };
    urls: { place: string; shop: string; method: string };
    honeypot: string;
    challenge: Challenge | null;
}

/** The field each error belongs to, in page order, for moving focus to the first one (audit OSA-20). */
const FOCUS_ORDER: [string, string][] = [
    ['shopId', 'step-shop'],
    ['fulfilment', 'step-method'],
    ['deliveryPostcode', 'delivery-postcode-check'],
    ['deliveryLine1', 'delivery-line1'],
    ['deliveryTown', 'delivery-town'],
    ['collectFrom', 'checkout-days'],
    ['customerName', 'customer-name'],
    ['customerMobile', 'customer-mobile'],
    ['customerEmail', 'customer-email'],
    ['customerNote', 'customer-note'],
    ['paymentMethod', 'step-pay'],
];

/** Checkout (OS-2, OS-9): shop, collect or delivery, day and slot, the customer's details, how to pay. */
export default function StoreCheckout(props: Props) {
    const { lines, shop, shops, method, delivery, days, day, slots, payments, totals, address, urls, honeypot, challenge } = props;
    const { store, basket } = usePage<StorePageProps>().props;
    const answer = useChallengeAnswer(challenge);
    const form = useForm({
        shopId: shop?.id ?? '',
        collectFrom: '',
        customerName: '',
        customerMobile: '',
        customerEmail: '',
        deliveryLine1: '',
        deliveryLine2: '',
        deliveryTown: '',
        deliveryNotes: '',
        customerNote: '',
        substitutionsOk: false as boolean,
        paymentMethod: payments.length === 1 ? payments[0].value : '',
        [honeypot]: '',
    });
    const { setData } = form;
    const firstPayment = payments.find((p) => p.available)?.value ?? '';

    // The ways to pay follow the method: keep a choice that is still offered, else take the only one.
    useEffect(() => {
        setData((data) =>
            payments.some((p) => p.value === data.paymentMethod && p.available)
                ? data
                : { ...data, paymentMethod: payments.length === 1 ? firstPayment : '' },
        );
    }, [payments, firstPayment, setData]);

    if (!store || !basket) {
        return null;
    }

    const isDelivery = method === 'delivery';
    const slot = slots.find((s) => s.from === form.data.collectFrom);
    const dayLabel = days.find((d) => d.date === day);
    const errors = form.errors as Record<string, string | undefined>;
    const general = errors.lines ?? errors.store ?? errors.challenge ?? errors[honeypot];
    const deliveryReady = !isDelivery || (delivery !== null && delivery.problem === null);
    const card = form.data.paymentMethod === 'cardOnline';
    // OSA-1: the page works out its small check in the background first (a fraction of a second).
    const checked = challenge === null || answer !== null;
    const canPlace = store.takingOrders && lines.length > 0 && shop !== null && deliveryReady && payments.length > 0 && checked;

    const chooseDay = (date: string) => {
        form.setData('collectFrom', '');
        router.get(window.location.pathname, { day: date }, { preserveState: true, preserveScroll: true, only: ['day', 'slots', 'days'] });
    };
    const focusFirstError = (found: Record<string, string>) => {
        const target = FOCUS_ORDER.find(([key]) => found[key])?.[1] ?? 'checkout-errors';
        window.setTimeout(() => document.getElementById(target)?.focus(), 0);
    };
    const place = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            shopId: shop?.id ?? '',
            fulfilment: method,
            deliveryPostcode: delivery?.postcode ?? '',
            challenge: challenge?.token ?? '',
            challengeAnswer: answer ?? '',
        }));
        form.post(urls.place, {
            // OSA-10: keep the position only when the checkout comes back with errors, so the confirmation opens at its top.
            preserveScroll: (page) => page.component === 'store/checkout',
            onError: focusFirstError,
        });
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

    let n = 1;
    const payNote = card
        ? 'You pay now by card on our secure payment page.'
        : form.data.paymentMethod === 'payOnDelivery'
          ? 'You pay the driver when your order arrives.'
          : 'You pay at the shop when you collect.';
    const placeLabel = !store.takingOrders
        ? 'Not taking orders'
        : !checked
          ? 'One moment…'
          : card
            ? `Pay ${money(totals.grand)} by card`
            : `Place order · ${money(totals.grand)}`;

    return (
        <StoreLayout title="Checkout">
            <h1 className="font-display mb-6 text-5xl leading-[0.95] font-extrabold tracking-[-0.045em] sm:text-[64px]">Checkout</h1>
            {general && (
                <div
                    id="checkout-errors"
                    tabIndex={-1}
                    role="alert"
                    className="ring-sale mb-6 flex items-start gap-3 rounded-3xl bg-white px-5 py-4 ring-2"
                >
                    <CircleAlert aria-hidden="true" className="text-sale mt-0.5 size-5 shrink-0" />
                    <p className="font-semibold">{general}</p>
                </div>
            )}
            <form onSubmit={place} noValidate className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-4">
                    {shops.length > 1 && (
                        <Step n={n++} id="step-shop" title="Which shop?">
                            <ShopChoice shop={shop} shops={shops} chooseUrl={urls.shop} />
                        </Step>
                    )}
                    <Step n={n++} id="step-method" title={delivery ? 'Collect or delivery?' : 'Click & collect'}>
                        {delivery ? (
                            <MethodChoice method={method} delivery={delivery} url={urls.method} shopName={shop.name} />
                        ) : (
                            <ShopChoice shop={shop} shops={[]} chooseUrl={urls.shop} />
                        )}
                        {isDelivery && delivery && (
                            <PostcodeCheck
                                delivery={delivery}
                                url={urls.method}
                                error={errors.deliveryPostcode}
                                label={address.postcodeLabel}
                                example={address.postcodeExample}
                            />
                        )}
                        {errors.fulfilment && (
                            <p role="alert" className="text-sale text-sm font-semibold">
                                {errors.fulfilment}
                            </p>
                        )}
                    </Step>
                    {isDelivery && delivery?.covered && (
                        <Step n={n++} id="step-address" title="Delivery address">
                            <AddressFields values={form.data} errors={errors} onChange={(key, value) => form.setData(key, value)} />
                        </Step>
                    )}
                    <Step n={n++} id="step-time" title={isDelivery ? 'Choose a delivery time' : 'Choose a time'}>
                        <SlotPicker
                            days={days}
                            day={day}
                            slots={slots}
                            value={form.data.collectFrom}
                            onDay={chooseDay}
                            onSlot={(from) => form.setData('collectFrom', from)}
                            error={errors.collectFrom}
                            word={isDelivery ? 'delivery' : 'collection'}
                        />
                    </Step>
                    <Step n={n++} id="step-details" title="Your details">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="customer-name"
                                label="Full name"
                                autoComplete="name"
                                value={form.data.customerName}
                                onChange={(e) => form.setData('customerName', e.target.value)}
                                error={errors.customerName}
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
                                error={errors.customerMobile}
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
                                    error={errors.customerEmail}
                                    required
                                />
                            </div>
                        </div>
                        <CheckoutExtras
                            note={form.data.customerNote}
                            substitutions={form.data.substitutionsOk}
                            onNote={(value) => form.setData('customerNote', value)}
                            onSubstitutions={(value) => form.setData('substitutionsOk', value)}
                            error={errors.customerNote}
                        />
                        <Honeypot name={honeypot} value={String(form.data[honeypot] ?? '')} onChange={(value) => form.setData(honeypot, value)} />
                    </Step>
                    <Step n={n++} id="step-pay" title="Payment">
                        {payments.length === 0 ? (
                            <p className="bg-paper text-body rounded-[18px] px-5 py-4">
                                Delivery is not available right now. Choose click &amp; collect.
                            </p>
                        ) : (
                            <PaymentChoice
                                options={payments}
                                value={form.data.paymentMethod}
                                onChange={(value) => form.setData('paymentMethod', value)}
                                error={errors.paymentMethod}
                            />
                        )}
                    </Step>
                </div>

                <OrderSummary
                    title="Your order"
                    basket={basket}
                    totals={{ method, deliveryFee: isDelivery && !delivery?.covered ? null : totals.delivery, grand: totals.grand, payNote }}
                    className="lg:sticky lg:top-6 lg:w-[400px] lg:shrink-0"
                >
                    <span className="bg-lime order-first -mb-1 self-start rounded-full px-3 py-1.5 text-[13px] font-bold">
                        {slot && dayLabel ? `${dayLabel.label} ${slot.label}` : isDelivery ? 'Choose a delivery time' : 'Choose a collection time'}
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
                        {placeLabel}
                    </button>
                    <p className="text-muted text-xs leading-relaxed">
                        {isDelivery
                            ? `By placing the order you agree to have it delivered from ${shop.name}${card ? '' : ' and to pay the driver'}. Change or cancel it by calling the shop.`
                            : `By placing the order you agree to collect it at ${shop.name}${card ? '' : ' and pay there'}. Change or cancel it by calling the shop.`}
                    </p>
                </OrderSummary>
            </form>
        </StoreLayout>
    );
}
