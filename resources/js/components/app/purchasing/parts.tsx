import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, Link2 } from 'lucide-react';
import { documentHref, formatDay, KIND_SINGULAR, money, PurchasingStatus } from './format';
import { type LinkedDocument, type ProductName } from './types';

/** Product name with its SKU and an optional note or warning under it. */
export function ProductCell({ product, note, flag }: { product: ProductName; note?: string | null; flag?: string | null }) {
    return (
        <div className="grid min-w-40 leading-5">
            <span className="font-medium">{product.name}</span>
            {product.sku && <span className="text-muted-foreground font-mono text-xs">{product.sku}</span>}
            {note && <span className="text-muted-foreground text-xs">{note}</span>}
            {flag && <span className="text-warning-foreground text-xs font-medium">{flag}</span>}
        </div>
    );
}

/** Net, VAT and total (inc. VAT) under a lines table. */
export function Totals({
    net,
    vat,
    gross,
    extra,
}: {
    net: string | null;
    vat: string | null;
    gross: string | null;
    extra?: { label: string; value: string }[];
}) {
    const row = (label: string, value: string | null, strong = false) => (
        <div key={label} className={cn('flex items-baseline justify-between gap-6 py-1 text-sm', strong && 'border-t pt-2 text-base font-semibold')}>
            <span className={cn(!strong && 'text-muted-foreground')}>{label}</span>
            <span className="tabular-nums">{money(value)}</span>
        </div>
    );

    return (
        <div className="ml-auto w-full max-w-72 px-4 py-3">
            {extra?.map((e) => row(e.label, e.value))}
            {row('Net', net)}
            {row('VAT', vat)}
            {row('Total', gross, true)}
        </div>
    );
}

/** Documents linked to this one (order ↔ deliveries ↔ invoices ↔ credits ↔ returns). */
export function LinkedCard({ links, title = 'Linked documents' }: { links: LinkedDocument[]; title?: string }) {
    return (
        <SectionCard title={title} flush>
            {links.length === 0 ? (
                <EmptyState icon={Link2} title="Nothing linked" body="Linked deliveries, invoices and credits show here." className="py-8" />
            ) : (
                <ul className="divide-y">
                    {links.map((link) => {
                        const href = documentHref(link.kind, link.id);
                        const body = (
                            <>
                                <div className="grid min-w-0 leading-5">
                                    <span className="text-muted-foreground text-xs">{link.label ?? KIND_SINGULAR[link.kind]}</span>
                                    <span className="truncate font-mono text-sm font-medium">{link.reference ?? '—'}</span>
                                </div>
                                <span className="flex items-center gap-2">
                                    {link.status && <PurchasingStatus status={link.status} />}
                                    {href && <ChevronRight className="text-muted-foreground size-4" />}
                                </span>
                            </>
                        );

                        return (
                            <li key={`${link.kind}-${link.id}`}>
                                {href ? (
                                    <Link
                                        href={href}
                                        className="hover:bg-muted/50 flex items-center justify-between gap-3 px-4 py-3 transition-colors"
                                    >
                                        {body}
                                    </Link>
                                ) : (
                                    <div className="flex items-center justify-between gap-3 px-4 py-3">{body}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

/** A dated list of documents (an order's deliveries and invoices). */
export function toLinks(
    kind: LinkedDocument['kind'],
    label: string,
    rows: { id: string; reference: string; status: string | null; date: string | null; gross: string }[],
): LinkedDocument[] {
    return rows.map((r) => ({
        kind,
        id: r.id,
        label: `${label} · ${formatDay(r.date)} · ${money(r.gross)}`,
        reference: r.reference,
        status: r.status,
    }));
}
