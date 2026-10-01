import { AccountsPageLayout, TYPE_LABELS } from '@/components/app/accounts/accounts-page';
import { type ExportMappingsProps } from '@/components/app/accounts/export-types';
import { SectionCard } from '@/components/shared/section-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Info, LoaderCircle } from 'lucide-react';
import { type FormEvent } from 'react';

/** Our account codes and VAT codes → the package's codes (gap #8). Blank uses the suggested default. */
export default function AccountsExportMappings({ target, targets, accounts, vat, filters, canEdit }: ExportMappingsProps) {
    const label = targets.find((t) => t.value === target)?.label ?? target;
    const initial = {
        target,
        accounts: Object.fromEntries(accounts.map((a) => [a.code, a.theirs])) as Record<string, string>,
        vat: Object.fromEntries(vat.map((v) => [v.code, { sales: v.sales, purchases: v.purchases }])) as Record<
            string,
            { sales: string; purchases: string }
        >,
    };
    const { data, setData, put, processing, isDirty, reset } = useForm(initial);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('app.accounts.export.mappings.update'), { preserveScroll: true });
    };

    return (
        <AccountsPageLayout
            tab="export"
            filters={filters}
            title={`${label} mapping · Accounts`}
            description={`Which ${label} account and tax code each of your till's accounts goes to. Leave a box blank to use the suggestion.`}
            actions={
                <Button variant="outline" asChild>
                    <Link href={route('app.accounts.export.index', { from: filters.from, to: filters.to, target })}>
                        <ArrowLeft />
                        Back to export
                    </Link>
                </Button>
            }
        >
            <div className="flex flex-wrap items-center gap-3">
                <Select
                    value={target}
                    onValueChange={(value) =>
                        router.get(route('app.accounts.export.mappings'), { target: value, from: filters.from, to: filters.to })
                    }
                >
                    <SelectTrigger className="h-9 w-full sm:w-56" aria-label="Accounting package">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {targets.map((t) => (
                            <SelectItem key={t.value} value={t.value}>
                                {t.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <Alert variant="info">
                <Info />
                <AlertDescription>
                    Suggestions follow {label}&apos;s standard UK chart. Your till already posts VAT on its own line (2200 VAT output), so the
                    suggested tax codes do not ask {label} to work VAT out again. Some packages refuse journals to their own VAT control account: if
                    the import does, map 2200 to a liability account of your own. {!canEdit && 'Only someone with every shop can change the mapping.'}
                </AlertDescription>
            </Alert>

            <form onSubmit={submit} className="grid gap-6">
                <SectionCard title="Accounts" description={`${accounts.length} account codes used by your tills or with a suggestion.`} flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Code</TableHead>
                                <TableHead>Our account</TableHead>
                                <TableHead className="w-64">{label} account</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.map((a) => (
                                <TableRow key={a.code}>
                                    <TableCell className="font-mono text-xs tabular-nums">{a.code}</TableCell>
                                    <TableCell>
                                        <span className="font-medium">{a.name}</span>
                                        <p className="text-muted-foreground text-xs">{TYPE_LABELS[a.type] ?? a.type}</p>
                                    </TableCell>
                                    <TableCell>
                                        <Input
                                            aria-label={`${label} account for ${a.code} ${a.name}`}
                                            className="h-9"
                                            maxLength={100}
                                            disabled={!canEdit}
                                            placeholder={a.default ?? 'Not mapped'}
                                            value={data.accounts[a.code] ?? ''}
                                            onChange={(e) => setData('accounts', { ...data.accounts, [a.code]: e.target.value })}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SectionCard>

                <SectionCard
                    title="VAT codes"
                    description="The tax code on each line, by the till's VAT rate. Sales lines use the first; purchases and costs the second."
                    flush
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Our VAT rate</TableHead>
                                <TableHead className="w-56">On sales</TableHead>
                                <TableHead className="w-56">On purchases</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {vat.map((v) => (
                                <TableRow key={v.code}>
                                    <TableCell>
                                        <span className="font-medium">{v.name}</span>
                                        {v.code !== '-' && <p className="text-muted-foreground font-mono text-xs">{v.code}</p>}
                                    </TableCell>
                                    {(['sales', 'purchases'] as const).map((side) => (
                                        <TableCell key={side}>
                                            <Input
                                                aria-label={`${label} tax code for ${v.name} on ${side}`}
                                                className="h-9"
                                                maxLength={100}
                                                disabled={!canEdit}
                                                placeholder={side === 'sales' ? v.defaultSales : v.defaultPurchases}
                                                value={data.vat[v.code]?.[side] ?? ''}
                                                onChange={(e) =>
                                                    setData('vat', { ...data.vat, [v.code]: { ...data.vat[v.code], [side]: e.target.value } })
                                                }
                                            />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SectionCard>

                {canEdit && isDirty && (
                    <StickyFormBar message="You have unsaved changes.">
                        <Button type="button" variant="outline" onClick={() => reset()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            Save mapping
                        </Button>
                    </StickyFormBar>
                )}
            </form>
        </AccountsPageLayout>
    );
}
