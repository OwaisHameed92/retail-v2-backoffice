import { SectionCard } from '@/components/shared/section-card';
import { money } from '@/components/shared/trading/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { formatMoneyAsGiven, taxText } from '@/lib/country';
import { cn } from '@/lib/utils';
import { ChevronDown, RotateCcw } from 'lucide-react';
import { Fragment, useState } from 'react';
import { FLAGS, LEAD_BASIS, lineCost, qty } from './suggestion-format';
import { type Quantities, type SuggestionGroup, type SuggestionLine } from './suggestion-types';

export function FlagBadges({ line }: { line: SuggestionLine }) {
    if (line.flags.length === 0) {
        return null;
    }

    return (
        <TooltipProvider delayDuration={200}>
            <div className="mt-1 flex flex-wrap gap-1">
                {line.flags.map((flag) => (
                    <Tooltip key={flag}>
                        <TooltipTrigger asChild>
                            <Badge variant={FLAGS[flag].tone} className="cursor-default text-[11px]" tabIndex={0}>
                                {FLAGS[flag].label}
                                {flag === 'seasonal' && line.events.length > 0 ? `: ${line.events.join(', ')}` : ''}
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent>{FLAGS[flag].hint}</TooltipContent>
                    </Tooltip>
                ))}
            </div>
        </TooltipProvider>
    );
}

interface GroupProps {
    group: SuggestionGroup;
    lines: SuggestionLine[];
    quantities: Quantities;
    editable: boolean;
    showShop: boolean;
    onChange: (key: string, cases: number) => void;
}

/** One shop + supplier: the lines one purchase order would hold, with editable cases and the reasons per line. */
export function SuggestionGroupCard({ group, lines, quantities, editable, showShop, onChange }: GroupProps) {
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const total = lines.reduce((sum, l) => sum + lineCost(l, quantities[l.key] ?? l.suggestedCases), 0);
    const belowMinimum = group.minimumOrder !== null && total > 0 && total < Number(group.minimumOrder);

    return (
        <SectionCard
            title={showShop ? `${group.supplierName} · ${group.shopName}` : group.supplierName}
            description={`Arrives in ${group.leadDays} ${group.leadDays === 1 ? 'day' : 'days'} (${LEAD_BASIS[group.leadBasis](group)}). Each order covers ${group.leadDays + group.reviewDays} days, until the next one arrives.`}
            actions={
                <div className="flex flex-wrap items-center justify-end gap-2 text-sm">
                    {belowMinimum && (
                        <Badge variant="warning">Below the {formatMoneyAsGiven(Number(group.minimumOrder).toFixed(2))} minimum order</Badge>
                    )}
                    <span className="font-medium tabular-nums">{money(total)}</span>
                    <span className="text-muted-foreground">{taxText('ex VAT')}</span>
                </div>
            }
            flush
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="min-w-56">Product</TableHead>
                        <TableHead className="text-right">On hand</TableHead>
                        <TableHead className="text-right">Coming</TableHead>
                        <TableHead className="text-right">Sells a day</TableHead>
                        <TableHead className="text-right">Days of cover</TableHead>
                        <TableHead className="text-right">Forecast</TableHead>
                        <TableHead className="min-w-40">Order (cases)</TableHead>
                        <TableHead className="text-right">Cost</TableHead>
                        <TableHead className="w-10">
                            <span className="sr-only">Why</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.map((line) => {
                        const cases = quantities[line.key] ?? line.suggestedCases;
                        const edited = cases !== line.suggestedCases;
                        const expanded = open[line.key] ?? false;
                        const coming = Number(line.onOrder) + Number(line.inTransit);

                        return (
                            <Fragment key={line.key}>
                                <TableRow className={cn(expanded && 'border-b-0')}>
                                    <TableCell className="align-top">
                                        <div className="font-medium">{line.name}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {[line.sku, line.supplierSku ? `Supplier code ${line.supplierSku}` : null, line.department]
                                                .filter(Boolean)
                                                .join(' · ') || 'No code'}
                                        </div>
                                        <FlagBadges line={line} />
                                    </TableCell>
                                    <TableCell className="text-right align-top tabular-nums">{qty(line.onHand)}</TableCell>
                                    <TableCell className="text-right align-top tabular-nums">{coming > 0 ? qty(coming) : '—'}</TableCell>
                                    <TableCell className="text-right align-top tabular-nums">
                                        {line.method === 'forecast' ? qty(line.rate) : '—'}
                                    </TableCell>
                                    <TableCell className="text-right align-top tabular-nums">
                                        {line.coverDays !== null ? qty(line.coverDays) : '—'}
                                    </TableCell>
                                    <TableCell className="text-right align-top tabular-nums">
                                        {line.method === 'forecast' ? (
                                            <span title={`Expected sales over the next ${line.horizonDays} days`}>{qty(line.forecast)}</span>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                max={10000}
                                                step={1}
                                                value={cases}
                                                disabled={!editable}
                                                aria-label={`Cases of ${line.name} for ${line.shopName}`}
                                                className="h-8 w-20 text-right tabular-nums"
                                                onChange={(e) => {
                                                    const next = Math.max(0, Math.min(10000, Math.floor(Number(e.target.value) || 0)));
                                                    onChange(line.key, next);
                                                }}
                                            />
                                            {edited && editable && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Back to the suggested ${line.suggestedCases}`}
                                                    title={`Suggested: ${line.suggestedCases}`}
                                                    onClick={() => onChange(line.key, line.suggestedCases)}
                                                >
                                                    <RotateCcw className="size-3.5" />
                                                </Button>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground mt-1 text-xs">
                                            × {line.caseQty} = {qty(cases * line.caseQty)} units
                                            {edited && <span className="text-foreground"> · suggested {line.suggestedCases}</span>}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right align-top tabular-nums">{money(lineCost(line, cases))}</TableCell>
                                    <TableCell className="align-top">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            aria-expanded={expanded}
                                            aria-label={`Why ${line.suggestedCases} ${line.suggestedCases === 1 ? 'case' : 'cases'} of ${line.name}`}
                                            onClick={() => setOpen({ ...open, [line.key]: !expanded })}
                                        >
                                            <ChevronDown className={cn('size-4 transition-transform', expanded && 'rotate-180')} />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                                {expanded && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell colSpan={9} className="bg-subtle pt-0 pb-3">
                                            <p className="text-muted-foreground mb-1 pt-3 text-xs font-medium">Why this quantity</p>
                                            <ul className="list-disc space-y-0.5 pl-5 text-sm">
                                                {line.reasons.map((reason) => (
                                                    <li key={reason}>{reason}</li>
                                                ))}
                                            </ul>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </SectionCard>
    );
}
