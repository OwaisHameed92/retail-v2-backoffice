import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { StoreLayout } from '@/components/store/store-layout';
import { useStore } from '@/components/store/use-store';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

type LegalKey = 'contact' | 'privacy' | 'terms' | 'cookies';

interface Props {
    page: LegalKey;
    title: string;
    intro: string;
    sections: { heading: string; paragraphs: string[] }[];
    extra: string | null;
}

const LABELS: Record<LegalKey, string> = { contact: 'Contact us', terms: 'Terms of sale', privacy: 'Privacy notice', cookies: 'Cookies' };

/** A paragraph that is an email address or a phone number line becomes a link. */
function Paragraph({ text }: { text: string }) {
    const email = text.match(/^Email: (\S+@\S+)$/);
    const phone = text.match(/^Phone: ([+\d ()-]+)$/);

    if (email) {
        return (
            <p>
                Email:{' '}
                <a href={`mailto:${email[1]}`} className="text-brand-text font-semibold underline underline-offset-2">
                    {email[1]}
                </a>
            </p>
        );
    }

    if (phone) {
        return (
            <p>
                Phone:{' '}
                <a href={`tel:${phone[1].replace(/[^\d+]/g, '')}`} className="text-brand-text font-semibold underline underline-offset-2">
                    {phone[1]}
                </a>
            </p>
        );
    }

    return <p>{text}</p>;
}

/**
 * OS-11 (audit OSA-2): the store's contact, privacy, terms and cookies pages, generated from its trader details, with
 * the shop's own text at the end and links between the four pages.
 */
export default function StoreLegal({ page, title, intro, sections, extra }: Props) {
    const store = useStore();
    const extraParagraphs = (extra ?? '')
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter(Boolean);

    return (
        <StoreLayout title={title}>
            <Breadcrumbs items={[{ label: 'Home', href: store?.urls.home }, { label: title }]} />
            <div className="mt-4 grid gap-8 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:gap-12">
                <nav aria-label="Shop information" className="order-2 lg:order-1">
                    <ul className="border-line flex flex-wrap gap-2 lg:flex-col lg:gap-1 lg:border-l lg:pl-4">
                        {(Object.keys(LABELS) as LegalKey[]).map((key) => {
                            const href = store?.urls[key];

                            return href ? (
                                <li key={key}>
                                    <Link
                                        href={href}
                                        aria-current={key === page ? 'page' : undefined}
                                        className={cn(
                                            'inline-flex min-h-10 items-center rounded-full px-3 text-sm font-medium lg:rounded-md lg:px-2',
                                            key === page ? 'bg-brand-soft text-brand-text font-semibold' : 'text-body hover:text-ink bg-white lg:bg-transparent',
                                        )}
                                    >
                                        {LABELS[key]}
                                    </Link>
                                </li>
                            ) : null;
                        })}
                    </ul>
                </nav>
                <article className="border-line order-1 rounded-2xl border bg-white p-6 sm:p-10 lg:order-2">
                    <h1 className="font-display text-3xl leading-tight font-bold tracking-[-0.02em] sm:text-4xl">{title}</h1>
                    <p className="text-body mt-3 max-w-2xl text-base leading-relaxed">{intro}</p>
                    <div className="mt-8 grid max-w-3xl gap-8">
                        {sections.map((section) => (
                            <section key={section.heading} className="grid gap-2.5">
                                <h2 className="text-ink text-lg font-bold">{section.heading}</h2>
                                <div className="text-body grid gap-2.5 leading-relaxed">
                                    {section.paragraphs.map((text) => (
                                        <Paragraph key={text} text={text} />
                                    ))}
                                </div>
                            </section>
                        ))}
                        {extraParagraphs.length > 0 && (
                            <section className="border-line grid gap-2.5 border-t pt-8">
                                <h2 className="text-ink text-lg font-bold">More from {store?.trader?.tradingName ?? store?.name ?? 'the shop'}</h2>
                                <div className="text-body grid gap-2.5 leading-relaxed whitespace-pre-line">
                                    {extraParagraphs.map((text, i) => (
                                        <p key={i}>{text}</p>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>
                </article>
            </div>
        </StoreLayout>
    );
}
