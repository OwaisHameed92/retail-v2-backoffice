import { ConsentPanel } from '@/components/app/customers/consent-panel';
import { CustomerForm } from '@/components/app/customers/customer-form';
import { BalanceText, balanceTone, dayLabel, money, number } from '@/components/app/customers/format';
import { LedgerTable } from '@/components/app/customers/ledger-table';
import { type CustomerShowProps } from '@/components/app/customers/types';
import { CustomerPrivacyPanel } from '@/components/app/privacy/customer-privacy-panel';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Coins, CreditCard, Eye, FileText, Info, Store, Wallet } from 'lucide-react';
import { useState } from 'react';

type Tab = 'history' | 'details' | 'consent' | 'privacy';

export default function CustomerShow({
    customer,
    account,
    ledger,
    ledgerFilters,
    shops,
    consent,
    canEdit,
    privacy,
    readOnlyReason,
}: CustomerShowProps) {
    const [tab, setTab] = useState<Tab>('history');
    const tone = balanceTone(account.balance);
    const optedIn = consent.current.filter((c) => c.state === 'given').length;
    const subline = [customer.card_no && `Card ${customer.card_no}`, customer.phone, customer.email].filter(Boolean).join(' · ');

    return (
        <AppLayout>
            <Head title={customer.name || 'Customer'} />

            <PageHeader
                title={customer.name || 'Unnamed customer'}
                media={<InitialsAvatar name={customer.name || '?'} size="lg" />}
                status={
                    customer.anonymisedAt ? (
                        <StatusPill tone="neutral">Anonymised</StatusPill>
                    ) : (
                        <StatusBadge status={customer.is_active ? 'active' : 'inactive'} />
                    )
                }
                description={subline || `Customer since ${dayLabel(customer.createdAt)}`}
                back={{ href: route('app.customers.index'), label: 'Customers' }}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('app.customers.statement', customer.id)}>
                            <FileText />
                            Statement
                        </Link>
                    </Button>
                }
                tabs={
                    <PageTabs
                        label="Customer sections"
                        value={tab}
                        onChange={(value) => setTab(value as Tab)}
                        tabs={[
                            { label: 'Account history', value: 'history', count: ledger.meta.total },
                            { label: 'Details', value: 'details' },
                            { label: 'Marketing consent', value: 'consent', count: optedIn },
                            ...(privacy ? [{ label: 'Privacy', value: 'privacy' }] : []),
                        ]}
                    />
                }
            />

            <StatGrid columns={4}>
                <StatCard
                    label={tone === 'credit' ? 'In credit' : 'Balance owed'}
                    value={money(Math.abs(Number(account.balance)))}
                    hint={account.overLimit ? 'Over their credit limit' : 'Added up from every shop'}
                    icon={Wallet}
                    tone={account.overLimit ? 'danger' : tone === 'owes' ? 'warning' : 'success'}
                />
                <StatCard label="Points" value={number(account.points)} hint="Available to spend" icon={Coins} tone="primary" />
                <StatCard
                    label="Credit available"
                    value={account.available === null ? 'No account' : money(Math.max(0, Number(account.available)))}
                    hint={Number(account.creditLimit) > 0 ? `Limit ${money(account.creditLimit)}` : 'No credit limit set'}
                    icon={CreditCard}
                    tone="neutral"
                />
                <StatCard
                    label="Shops used"
                    value={number(account.byShop.length)}
                    hint={account.byShop[0] ? `Mostly ${account.byShop[0].branch}` : 'No account activity yet'}
                    icon={Store}
                    tone="neutral"
                />
            </StatGrid>

            <div id={`tab-panel-${tab}`} role="tabpanel" className="grid gap-6">
                {tab === 'history' && (
                    <>
                        <Alert>
                            <Info className="size-4" />
                            <AlertDescription>
                                The balance and points are added up from every shop's account rows, so they match what every till shows once it has
                                synced. Payments and points adjustments are taken at a till.
                            </AlertDescription>
                        </Alert>
                        <LedgerTable ledger={ledger} filters={ledgerFilters} shops={shops} />
                        {account.byShop.length > 1 && (
                            <SectionCard title="By shop" description="How each shop's rows moved this customer's account." flush>
                                <ul className="divide-border divide-y">
                                    {account.byShop.map((shop) => (
                                        <li key={shop.branch} className="flex items-center justify-between gap-4 px-5 py-3 text-sm sm:px-6">
                                            <div className="grid leading-5">
                                                <span className="font-medium">{shop.branch}</span>
                                                <span className="text-muted-foreground text-xs">
                                                    {number(shop.count)} {shop.count === 1 ? 'entry' : 'entries'} · last {dayLabel(shop.lastAt)}
                                                </span>
                                            </div>
                                            <div className="grid justify-items-end leading-5">
                                                <BalanceText balance={shop.balance} />
                                                <span className="text-muted-foreground text-xs tabular-nums">{number(shop.points)} points</span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}
                    </>
                )}

                {tab === 'details' && (
                    <>
                        {!canEdit && (
                            <Alert>
                                <Eye className="size-4" />
                                <AlertDescription>
                                    {customer.anonymisedAt
                                        ? 'This customer was anonymised, so their details can no longer be changed.'
                                        : readOnlyReason === 'oneShop'
                                          ? 'Customers are shared by every shop. You can look, but only someone who manages all shops can change them.'
                                          : 'You can look at these details. Ask the owner or a manager to change them.'}
                                </AlertDescription>
                            </Alert>
                        )}
                        <CustomerForm key={customer.updatedAt ?? customer.id} customer={customer} canEdit={canEdit} />
                    </>
                )}

                {tab === 'consent' && <ConsentPanel consent={consent} name={customer.name || 'The customer'} />}

                {tab === 'privacy' && privacy && (
                    <CustomerPrivacyPanel customerId={customer.id} name={customer.name || 'this customer'} privacy={privacy} />
                )}
            </div>
        </AppLayout>
    );
}
