import BrandLogo from '@/components/brand-logo';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Props {
    page: string;
    title: string;
    /** Rendered on the server from resources/legal/<page>.md with raw HTML escaped. */
    html: string;
    pages: { key: string; title: string }[];
}

/** A public legal page (module 7.7): terms, privacy notice, DPA or sub-processors. */
export default function LegalShow({ page, title, html, pages }: Props) {
    const year = new Date().getFullYear();

    return (
        <div className="bg-background min-h-dvh">
            <Head title={title} />
            <header className="border-border border-b">
                <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href={route('home')} aria-label="Switch & Save home">
                        <BrandLogo className="h-9" />
                    </Link>
                    <Link href={route('login')} className="text-primary text-sm font-medium hover:underline">
                        Sign in
                    </Link>
                </div>
            </header>

            <div className="mx-auto grid max-w-4xl gap-8 px-4 py-8 sm:px-6 md:grid-cols-[12rem_minmax(0,1fr)]">
                <nav aria-label="Legal pages" className="flex gap-2 overflow-x-auto md:flex-col md:gap-1">
                    {pages.map((p) => (
                        <a
                            key={p.key}
                            href={route('legal', p.key)}
                            aria-current={p.key === page ? 'page' : undefined}
                            className={cn(
                                'rounded-md px-3 py-2 text-sm whitespace-nowrap',
                                p.key === page
                                    ? 'bg-primary-soft text-accent-foreground font-medium'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            {p.title}
                        </a>
                    ))}
                </nav>

                <main className="min-w-0">
                    <h1 className="text-foreground mb-6 text-2xl font-semibold tracking-[-0.02em]">{title}</h1>
                    <article
                        className={cn(
                            'text-foreground grid gap-4 text-[15px] leading-7',
                            '[&_a]:text-primary [&_a]:underline [&_h2]:mt-4 [&_h2]:text-lg [&_h2]:font-semibold',
                            '[&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6',
                            '[&_blockquote]:border-warning [&_blockquote]:bg-warning-soft [&_blockquote]:rounded-lg [&_blockquote]:border-l-4 [&_blockquote]:p-4',
                            '[&_td]:border-border [&_th]:border-border [&_table]:w-full [&_table]:text-sm [&_td]:border-b [&_td]:p-2 [&_th]:border-b [&_th]:p-2 [&_th]:text-left',
                        )}
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                </main>
            </div>

            <footer className="border-border text-muted-foreground border-t py-6 text-center text-xs">© {year} Switch &amp; Save.</footer>
        </div>
    );
}
