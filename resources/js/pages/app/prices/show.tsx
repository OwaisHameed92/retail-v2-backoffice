import { EveryShopDialog } from '@/components/app/pricing/every-shop-dialog';
import { changedAtLabel, difference, formatDateTime, pounds, PRICE_TONES } from '@/components/app/pricing/format';
import { PriceHistory } from '@/components/app/pricing/price-history';
import { SetPriceDialog } from '@/components/app/pricing/set-price-dialog';
import { type ProductPricesProps, type ShopPrices } from '@/components/app/pricing/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarX, Globe, Info, Pencil, Store, Undo2 } from 'lucide-react';
import { useState } from 'react';

type Ending = { shop: ShopPrices; unitId: string | null } | null;

export default function ProductPricesPage(props: ProductPricesProps) {
    const { product, units, shops, history, historyLimited, restrictedShop, canSetShopPrices, canSetEveryShop } = props;
    const [setting, setSetting] = useState<{ open: boolean; shopId: string | null }>({ open: false, shopId: null });
    const [every, setEvery] = useState<{ open: boolean; preselect: string[] }>({ open: false, preselect: [] });
    const [ending, setEnding] = useState<Ending>(null);
    const [cancelling, setCancelling] = useState<string | null>(null);
    const withOwn = shops.filter((s) => s.current !== null).length;

    return (
        <AppLayout>
            <Head title={`Prices · ${product.name}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title={product.name}
                    description={`${product.sku ? `${product.sku} · ` : ''}Business price ${pounds(product.sellPrice)} · ${withOwn} of ${shops.length} shop${shops.length === 1 ? '' : 's'} with their own price`}
                    back={{ href: route('app.prices.index'), label: 'Prices' }}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={route('app.products.show', product.id)}>Product details</Link>
                            </Button>
                            {canSetShopPrices && (
                                <Button onClick={() => setSetting({ open: true, shopId: restrictedShop })}>
                                    <MoneyIcon />
                                    Set a shop price
                                </Button>
                            )}
                        </>
                    }
                />

                {restrictedShop !== null && (
                    <Alert variant="info">
                        <Info />
                        <AlertDescription>
                            You manage one shop: you can set and end its own price. The business price is shared by every shop, so only someone who
                            manages all shops can change it.
                        </AlertDescription>
                    </Alert>
                )}

                <SectionCard
                    title="Business price"
                    description="What every shop charges unless it has its own price."
                    actions={
                        canSetEveryShop ? (
                            <Button variant="outline" onClick={() => setEvery({ open: true, preselect: [] })}>
                                <Globe />
                                Change for every shop
                            </Button>
                        ) : undefined
                    }
                >
                    <p className="text-3xl font-semibold tracking-tight tabular-nums">{pounds(product.sellPrice)}</p>
                </SectionCard>

                <SectionCard title="Shop prices now" description="Each shop's price at this moment, and anything scheduled." flush>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Shop</TableHead>
                                    <TableHead className="text-right">Price now</TableHead>
                                    <TableHead>Where it comes from</TableHead>
                                    <TableHead>Coming up</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shops.map((shop) => (
                                    <TableRow key={shop.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Store className="text-muted-foreground size-4" aria-hidden />
                                                <span className="font-medium">{shop.name}</span>
                                                {!shop.isActive && <StatusBadge status="inactive" label="Closed" />}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <div className="grid leading-5">
                                                <span className="font-medium">{pounds(shop.current?.price ?? product.sellPrice)}</span>
                                                {shop.current && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {difference(shop.current.price, product.sellPrice) ?? 'Same as business'}
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {shop.current ? (
                                                <div className="grid leading-5">
                                                    <span>Own price · {changedAtLabel(shop.current.changedAt)}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        Since {formatDateTime(shop.current.validFrom)}
                                                        {shop.current.validTo ? ` until ${formatDateTime(shop.current.validTo)}` : ''}
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">Business price</span>
                                            )}
                                            {shop.unitPrices.map((u) => (
                                                <div key={u.id} className="text-muted-foreground text-xs">
                                                    {u.unit}: {pounds(u.price)}
                                                </div>
                                            ))}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {shop.scheduled.length === 0 ? (
                                                <span className="text-muted-foreground">—</span>
                                            ) : (
                                                shop.scheduled.map((s) => (
                                                    <div key={s.id} className="flex items-center gap-2 leading-6">
                                                        <StatusBadge status="scheduled" tones={PRICE_TONES} />
                                                        <span className="tabular-nums">
                                                            {pounds(s.price)} from {formatDateTime(s.validFrom)}
                                                            {s.unit ? ` (${s.unit})` : ''}
                                                        </span>
                                                        {canSetShopPrices && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => setCancelling(s.id)}
                                                                aria-label={`Cancel scheduled price ${pounds(s.price)}`}
                                                            >
                                                                <CalendarX />
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {canSetShopPrices && (
                                                <RowActions
                                                    label={`Actions for ${shop.name}`}
                                                    actions={[
                                                        {
                                                            label: 'Set price',
                                                            icon: Pencil,
                                                            onSelect: () => setSetting({ open: true, shopId: shop.id }),
                                                        },
                                                        ...(shop.current
                                                            ? [
                                                                  {
                                                                      label: 'Back to business price',
                                                                      icon: Undo2,
                                                                      onSelect: () => setEnding({ shop, unitId: null }),
                                                                  },
                                                              ]
                                                            : []),
                                                        ...(shop.current && canSetEveryShop
                                                            ? [
                                                                  {
                                                                      label: 'Use for every shop…',
                                                                      icon: Globe,
                                                                      onSelect: () => setEvery({ open: true, preselect: [shop.id] }),
                                                                  },
                                                              ]
                                                            : []),
                                                        ...shop.unitPrices.map((u) => ({
                                                            label: `End ${u.unit} price`,
                                                            icon: Undo2,
                                                            onSelect: () => setEnding({ shop, unitId: u.unitId }),
                                                        })),
                                                    ]}
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </SectionCard>

                <PriceHistory rows={history} shops={shops} limited={historyLimited} />
            </div>

            {canSetShopPrices && (
                <SetPriceDialog
                    open={setting.open}
                    onOpenChange={(open) => setSetting((s) => ({ ...s, open }))}
                    productId={product.id}
                    businessPrice={product.sellPrice}
                    shops={shops.filter((s) => s.isActive)}
                    units={units}
                    shopId={setting.shopId}
                />
            )}
            {canSetEveryShop && (
                <EveryShopDialog
                    open={every.open}
                    onOpenChange={(open) => setEvery((s) => ({ ...s, open }))}
                    productId={product.id}
                    businessPrice={product.sellPrice}
                    shops={shops}
                    preselect={every.preselect}
                />
            )}
            <ConfirmDialog
                open={ending !== null}
                onOpenChange={(open) => !open && setEnding(null)}
                title={`End ${ending?.shop.name ?? 'this shop'}'s own price?`}
                description="From now the shop sells at the business price. Its tills get the change at their next sync. The old price stays in the history."
                confirmLabel="End shop price"
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.post(
                            route('app.prices.shop.end', product.id),
                            { branch_id: ending?.shop.id ?? '', product_unit_id: ending?.unitId ?? null },
                            { preserveScroll: true, onFinish: () => resolve(setEnding(null)) },
                        ),
                    )
                }
            />
            <ConfirmDialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && setCancelling(null)}
                title="Cancel this scheduled price?"
                description="It will never start. The shop keeps its current price."
                confirmLabel="Cancel scheduled price"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.post(
                            route('app.prices.rows.cancel', cancelling ?? ''),
                            {},
                            { preserveScroll: true, onFinish: () => resolve(setCancelling(null)) },
                        ),
                    )
                }
            />
        </AppLayout>
    );
}
