import { money, PurchasingTabs } from '@/components/app/purchasing/format';
import { type StatementsProps } from '@/components/app/purchasing/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { Factory, Scale } from 'lucide-react';

/** Supplier statements (module 5.2): what the business owes each supplier = invoices − credit notes − payments. */
export default function SupplierStatements({ balances, summary, shop, shops, oneShop }: StatementsProps) {
    const open = (id: string) => router.visit(route('app.purchasing.statements.show', { supplier: id, ...(shop && !oneShop ? { shop } : {}) }));

    return (
        <AppLayout>
            <Head title="Supplier statements · Purchasing" />

            <PageHeader
                title="Purchasing"
                description="What you owe each supplier: invoices less credit notes and payments, from your shops' own records."
                tabs={<PurchasingTabs current="statements" />}
            />

            <StatGrid columns={3}>
                <StatCard label="Owed to suppliers" value={money(summary.owed)} icon={Scale} tone="primary" hint="Suppliers with a balance" />
                <StatCard label="In credit" value={money(summary.inCredit)} icon={Scale} tone="success" hint="Suppliers who owe you" />
                <StatCard label="Suppliers" value={balances.length} icon={Factory} tone="neutral" hint="With invoices, credits or payments" />
            </StatGrid>

            <SectionCard
                title="Balances"
                description="Draft invoices and reversed payments are left out."
                actions={
                    !oneShop &&
                    shops.length > 1 && (
                        <FilterSelect
                            value={shop}
                            onChange={(next) =>
                                router.get(route('app.purchasing.statements.index'), next ? { shop: next } : {}, {
                                    preserveState: true,
                                    replace: true,
                                })
                            }
                            all="Every shop"
                            options={shops.map((s) => ({ value: s.id, label: s.name }))}
                            label="Filter by shop"
                        />
                    )
                }
                flush
            >
                {balances.length === 0 ? (
                    <EmptyState
                        icon={Scale}
                        title="No supplier documents yet"
                        body="Invoices, credit notes and payments entered on a till appear here after it syncs."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Supplier</TableHead>
                                <TableHead className="text-right">Invoiced</TableHead>
                                <TableHead className="text-right">Credited</TableHead>
                                <TableHead className="text-right">Paid</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {balances.map((b) => (
                                <TableRow key={b.id} className="cursor-pointer" onClick={() => open(b.id)}>
                                    <TableCell>
                                        <EntityCell
                                            name={b.name}
                                            shape="square"
                                            icon={Factory}
                                            href={route('app.purchasing.statements.show', b.id)}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{money(b.invoiced)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(b.credited)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(b.paid)}</TableCell>
                                    <TableCell
                                        className={cn('text-right font-medium tabular-nums', Number(b.balance) < 0 && 'text-success-foreground')}
                                    >
                                        {money(b.balance)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="font-medium">Total</TableCell>
                                <TableCell className="text-right tabular-nums">{money(summary.invoiced)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(summary.credited)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(summary.paid)}</TableCell>
                                <TableCell className="text-right font-semibold tabular-nums">{money(summary.balance)}</TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                )}
            </SectionCard>
        </AppLayout>
    );
}
