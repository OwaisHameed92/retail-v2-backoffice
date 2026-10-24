import { StoreLayout } from '@/components/store/store-layout';
import { useStore } from '@/components/store/use-store';
import { Link } from '@inertiajs/react';

interface Props {
    status: number;
    title: string;
    message: string;
}

/** The public store's error pages (404, 403 order links, 429): in the shop's style, or plain when the shop is unknown. */
export default function StoreError({ status, title, message }: Props) {
    const store = useStore();

    return (
        <StoreLayout title={title}>
            <section className="border-line mx-auto my-6 flex max-w-xl flex-col items-start gap-4 rounded-2xl border bg-white p-7 sm:my-12 sm:p-10">
                <span className="bg-lime text-on-lime rounded-full px-3 py-1 text-xs font-semibold">Error {status}</span>
                <h1 className="font-display text-4xl leading-[0.95] font-extrabold tracking-[-0.045em] sm:text-6xl">{title}</h1>
                <p className="text-body leading-relaxed">{message}</p>
                {store ? (
                    <Link
                        href={store.urls.home}
                        className="bg-brand text-on-brand hover:bg-brand-hover inline-flex h-11 items-center rounded-full px-5 text-sm font-semibold transition"
                    >
                        Back to {store.name}
                    </Link>
                ) : (
                    <p className="text-muted text-sm">Online store by Switch &amp; Save</p>
                )}
            </section>
        </StoreLayout>
    );
}
