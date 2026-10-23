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
            <section className="mx-auto my-6 flex max-w-2xl flex-col items-start gap-5 rounded-[32px] bg-white p-8 sm:my-12 sm:p-12">
                <span className="bg-lime rounded-full px-3 py-1.5 text-[13px] font-bold">Error {status}</span>
                <h1 className="font-display text-4xl leading-[0.95] font-extrabold tracking-[-0.045em] sm:text-6xl">{title}</h1>
                <p className="text-body text-lg leading-relaxed">{message}</p>
                {store ? (
                    <Link
                        href={store.urls.home}
                        className="bg-brand text-on-brand inline-flex min-h-14 items-center rounded-full px-7 font-bold hover:brightness-110"
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
