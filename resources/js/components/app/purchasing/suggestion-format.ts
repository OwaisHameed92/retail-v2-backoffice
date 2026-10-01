import { type Quantities, type SuggestionFlag, type SuggestionGroup, type SuggestionLine } from './suggestion-types';

type Tone = 'danger' | 'warning' | 'info' | 'violet' | 'neutral';

export const FLAGS: Record<SuggestionFlag, { label: string; tone: Tone; hint: string }> = {
    negativeStock: { label: 'Negative stock', tone: 'danger', hint: 'The till shows less than none. Count it before ordering.' },
    runsOut: { label: 'Runs out first', tone: 'danger', hint: 'Expected to sell out before this order arrives.' },
    outOfStock: { label: 'Out of stock', tone: 'warning', hint: 'None on hand now.' },
    wasteRisk: { label: 'May go out of date', tone: 'warning', hint: 'Even one case is more than it sells before its date.' },
    shortLife: { label: 'Short life', tone: 'warning', hint: 'Capped at what sells before the best-before date.' },
    spike: { label: 'Selling faster', tone: 'info', hint: 'Last week sold well above the average.' },
    seasonal: { label: 'Seasonal', tone: 'info', hint: 'An upcoming event changes the forecast.' },
    slowing: { label: 'Selling slower', tone: 'neutral', hint: 'Last week sold well below the average.' },
    overstock: { label: 'Overstocked', tone: 'violet', hint: 'Enough stock for many weeks.' },
    noHistory: { label: 'No recent sales', tone: 'neutral', hint: 'No sales in the last weeks: the min/max levels decide.' },
    capped: { label: 'At max level', tone: 'neutral', hint: 'Capped so stock stays under the maximum level.' },
};

const qtyFormat = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

/** "12", "3.5" from a decimal string; null → "—". */
export function qty(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : qtyFormat.format(Number(value));
}

export const LEAD_BASIS: Record<SuggestionGroup['leadBasis'], (g: SuggestionGroup) => string> = {
    shop: (g) => `from ${g.leadSamples} deliveries here`,
    business: (g) => `from ${g.leadSamples} deliveries to your shops`,
    supplier: () => "the supplier's usual lead time",
    default: () => 'no delivery history yet',
};

export interface PlannedOrder {
    group: SuggestionGroup;
    lines: { line: SuggestionLine; cases: number }[];
    cases: number;
    cost: number;
}

/** The orders "Create purchase orders" would draft: one per shop and supplier, lines with at least one case. */
export function planOrders(lines: SuggestionLine[], groups: SuggestionGroup[], quantities: Quantities): PlannedOrder[] {
    const byKey = new Map(groups.map((g) => [g.key, g]));
    const orders = new Map<string, PlannedOrder>();

    for (const line of lines) {
        const cases = quantities[line.key] ?? line.suggestedCases;
        const group = byKey.get(line.groupKey);
        if (cases <= 0 || !group) {
            continue;
        }
        const order = orders.get(group.key) ?? { group, lines: [], cases: 0, cost: 0 };
        order.lines.push({ line, cases });
        order.cases += cases;
        order.cost += cases * line.caseQty * Number(line.unitCost);
        orders.set(group.key, order);
    }

    return [...orders.values()];
}

/** Cost of one line at the chosen cases (display only; the server prices the order). */
export function lineCost(line: SuggestionLine, cases: number): number {
    return cases * line.caseQty * Number(line.unitCost);
}
