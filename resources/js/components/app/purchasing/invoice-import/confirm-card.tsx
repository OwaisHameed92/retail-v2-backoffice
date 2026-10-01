import { cost } from '@/components/app/purchasing/format';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CheckCheck, Info, TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { type InvoiceReviewProps } from './types';

type OrderChoice = 'none' | 'draft' | 'sent';

const ORDER_OPTIONS: { value: OrderChoice; label: string; help: string }[] = [
    { value: 'none', label: 'Just record the invoice', help: 'Nothing is sent to the shop.' },
    { value: 'sent', label: 'Place a head-office order', help: 'The shop can book the delivery in against it at its next sync.' },
    { value: 'draft', label: 'Draft a head-office order', help: 'The shop sees it, but cannot book it in until you send it.' },
];

interface ConfirmCardProps {
    props: InvoiceReviewProps;
    dirty: boolean;
}

/** Preview of what confirming does (cost prices, an order), the "I have checked" tick, and Confirm. */
export function ConfirmCard({ props, dirty }: ConfirmCardProps) {
    const { import: record, analysis, draft, linked, can } = props;
    const changes = analysis?.costChanges ?? [];
    const warnings = (analysis?.issues ?? []).filter((i) => i.level === 'warning').length;
    const blocked = (analysis?.issues ?? []).find((i) => i.level === 'error');
    const canOrder = can.order && !linked?.order && Boolean(draft?.supplierId);
    const [order, setOrder] = useState<OrderChoice>('none');
    const [chosen, setChosen] = useState<string[]>([]);
    const [acknowledged, setAcknowledged] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const ready = !dirty && !blocked && (warnings === 0 || acknowledged);

    const confirm = () =>
        new Promise<void>((resolve) =>
            router.post(
                route('app.purchasing.invoices.import.confirm', record.id),
                { order, costUpdates: chosen, acknowledged },
                { preserveScroll: true, onError: (e) => setErrors(e), onFinish: () => resolve() },
            ),
        );

    const summary = [
        `Invoice ${draft?.invoiceNumber ?? ''} is recorded for ${record.shop.name}.`,
        order !== 'none' ? (order === 'sent' ? 'A head-office order is placed for the shop.' : 'A draft head-office order is saved for the shop.') : null,
        chosen.length > 0 ? `${chosen.length} cost ${chosen.length === 1 ? 'price changes' : 'prices change'} for every shop.` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <SectionCard title="Confirm" description="Nothing changes until you confirm. The shop's own invoice and delivery stay on its till.">
            <div className="grid gap-5">
                <div className="grid gap-2">
                    <h3 className="text-sm font-medium">Cost prices</h3>
                    {changes.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No matched product has a different cost on this invoice.</p>
                    ) : (
                        <>
                            {!can.costs && (
                                <p className="text-muted-foreground text-sm">Only someone who manages the catalogue for every shop can change cost prices.</p>
                            )}
                            <ul className="divide-border divide-y rounded-md border" aria-label="Cost price changes">
                                {changes.map((change) => {
                                    const up = Number(change.to) > Number(change.from);
                                    const id = `cost-${change.productId}`;

                                    return (
                                        <li key={change.productId} className="flex items-center gap-3 px-3 py-2">
                                            {can.costs && (
                                                <Checkbox
                                                    id={id}
                                                    checked={chosen.includes(change.productId)}
                                                    onCheckedChange={(on) =>
                                                        setChosen((current) =>
                                                            on ? [...current, change.productId] : current.filter((c) => c !== change.productId),
                                                        )
                                                    }
                                                />
                                            )}
                                            <label htmlFor={id} className="grid min-w-0 flex-1 text-sm">
                                                <span className="truncate font-medium">{change.name}</span>
                                                <span className="text-muted-foreground text-xs">Line {change.line}</span>
                                            </label>
                                            <span className="flex shrink-0 items-center gap-1.5 text-sm tabular-nums">
                                                <span className="text-muted-foreground">{cost(change.from)}</span>→<span className="font-medium">{cost(change.to)}</span>
                                                {change.changePercent !== null && (
                                                    <span className={cn('flex items-center text-xs', up ? 'text-warning-foreground' : 'text-success-foreground')}>
                                                        {up ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
                                                        {Math.abs(Number(change.changePercent))}%
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                            {can.costs && changes.length > 1 && (
                                <div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setChosen(chosen.length === changes.length ? [] : changes.map((c) => c.productId))}
                                    >
                                        {chosen.length === changes.length ? 'Clear all' : 'Choose all'}
                                    </Button>
                                </div>
                            )}
                            {errors.costUpdates && <p className="text-destructive text-sm">{errors.costUpdates}</p>}
                        </>
                    )}
                </div>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Order</legend>
                    {linked?.order ? (
                        <p className="text-muted-foreground text-sm">
                            This invoice is for order {linked.order.reference}. The shop books the delivery in against it on the till.
                        </p>
                    ) : !can.order ? (
                        <p className="text-muted-foreground text-sm">Only someone who manages purchasing for every shop can place a head-office order.</p>
                    ) : (
                        ORDER_OPTIONS.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2',
                                    order === option.value && 'border-primary bg-primary-soft',
                                    option.value !== 'none' && !canOrder && 'pointer-events-none opacity-60',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="order"
                                    value={option.value}
                                    checked={order === option.value}
                                    disabled={option.value !== 'none' && !canOrder}
                                    onChange={() => setOrder(option.value)}
                                    className="accent-primary mt-1"
                                />
                                <span className="grid text-sm">
                                    <span className="font-medium">{option.label}</span>
                                    <span className="text-muted-foreground text-xs">{option.help}</span>
                                </span>
                            </label>
                        ))
                    )}
                    {can.order && !linked?.order && !draft?.supplierId && <p className="text-muted-foreground text-xs">Pick the supplier to place an order.</p>}
                    {errors.order && <p className="text-destructive text-sm">{errors.order}</p>}
                </fieldset>

                {warnings > 0 && (
                    <label className="bg-warning-soft flex items-start gap-3 rounded-md px-3 py-2 text-sm">
                        <Checkbox checked={acknowledged} onCheckedChange={(on) => setAcknowledged(on === true)} className="mt-0.5" />
                        <span>
                            I have checked the {warnings} {warnings === 1 ? 'warning' : 'warnings'} above and want to confirm anyway.
                            {errors.acknowledged && <span className="text-destructive block">{errors.acknowledged}</span>}
                        </span>
                    </label>
                )}

                {(dirty || blocked || errors.import) && (
                    <Alert variant={errors.import || blocked ? 'destructive' : 'info'}>
                        <Info />
                        <AlertDescription>{errors.import ?? blocked?.message ?? 'Save your changes first: the checks run on the saved invoice.'}</AlertDescription>
                    </Alert>
                )}

                <div className="flex justify-end">
                    <ConfirmDialog
                        trigger={
                            <Button type="button" disabled={!ready || !can.confirm}>
                                <CheckCheck />
                                Confirm invoice
                            </Button>
                        }
                        title={`Confirm invoice ${draft?.invoiceNumber ?? ''}?`}
                        description={summary}
                        confirmLabel="Confirm invoice"
                        onConfirm={confirm}
                    />
                </div>
            </div>
        </SectionCard>
    );
}
