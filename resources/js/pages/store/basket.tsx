import { BasketLineRow } from '@/components/store/basket-line';
import { OrderSummary } from '@/components/store/order-summary';
import { StoreLayout } from '@/components/store/store-layout';
import { type BasketLine, type StorePageProps } from '@/components/store/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, ShoppingBag } from 'lucide-react';

interface Props {
    lines: BasketLine[];
}

/** The basket: priced again from the shop's catalogue on every view; quantities change at once. */
export default function StoreBasket({ lines }: Props) {
    const { store, basket } = usePage<StorePageProps>().props;

    if (!store || !basket) {
        return null;
    }

    return (
        <StoreLayout title="Your basket" active="basket">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <section aria-labelledby="basket-title" className="flex min-w-0 flex-1 flex-col gap-4">
                    <h1 id="basket-title" className="font-display text-5xl leading-[0.95] font-extrabold tracking-[-0.045em] sm:text-[64px]">
                        Your basket{' '}
                        <span className="text-muted align-middle font-sans text-lg font-semibold tracking-normal sm:text-[22px]">
                            {basket.count === 1 ? '1 item' : `${basket.count} items`}
                        </span>
                    </h1>
                    {lines.length === 0 ? (
                        <div className="flex flex-col items-start gap-4 rounded-[28px] bg-white p-8">
                            <span className="bg-lime flex size-14 items-center justify-center rounded-full">
                                <ShoppingBag aria-hidden="true" className="size-7" />
                            </span>
                            <p className="font-display text-2xl font-extrabold tracking-tight">Your basket is empty</p>
                            <p className="text-body">{store.wording?.basketEmpty ?? 'Add a few things and pick a time to collect them.'}</p>
                            <Link
                                href={store.urls.home}
                                className="bg-brand text-on-brand inline-flex min-h-12 items-center gap-2 rounded-full px-6 font-bold hover:brightness-110"
                            >
                                Start shopping
                                <ArrowRight aria-hidden="true" className="size-4" />
                            </Link>
                        </div>
                    ) : (
                        <ul className="flex flex-col gap-2.5">
                            {lines.map((line) => (
                                <BasketLineRow key={line.product.id} line={line} editable />
                            ))}
                        </ul>
                    )}
                    {lines.length > 0 && store.shop && (
                        <p className="text-body rounded-[24px] bg-white px-5 py-4 text-[15px]">
                            Prices and stock are for <strong className="text-ink">{store.shop.name}</strong>.
                            {store.shopCount > 1 && ' You can choose another shop at checkout.'}
                        </p>
                    )}
                </section>

                {lines.length > 0 && (
                    <OrderSummary title="Order summary" basket={basket} className="lg:sticky lg:top-6 lg:w-[400px] lg:shrink-0">
                        {store.takingOrders ? (
                            <Link
                                href={store.urls.checkout}
                                className="bg-brand text-on-brand mt-1 flex min-h-[60px] items-center justify-center gap-2 rounded-full text-[17px] font-extrabold hover:brightness-110"
                            >
                                Go to checkout
                                <ArrowRight aria-hidden="true" className="size-5" />
                            </Link>
                        ) : (
                            <p className="bg-warn-soft mt-1 rounded-2xl px-4 py-3 text-sm font-semibold">
                                We are not taking online orders right now.
                            </p>
                        )}
                        <Link
                            href={store.urls.home}
                            className="text-ink flex min-h-11 items-center justify-center font-semibold underline-offset-4 hover:underline"
                        >
                            Keep shopping
                        </Link>
                    </OrderSummary>
                )}
            </div>
        </StoreLayout>
    );
}
