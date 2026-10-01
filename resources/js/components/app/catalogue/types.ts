import { type MasterRow } from '@/components/admin/catalogue/types';
import { type CatalogueOptions } from '@/components/app/products/types';
import { type Paginated } from '@/components/shared/data-table';

export type CatalogueRow = MasterRow & { inCatalogue: boolean };

export type YourDepartment = CatalogueOptions['departments'][number];

export interface DepartmentCount {
    value: string;
    label: string;
    count: number;
}

/** CatalogueSearch::for() */
export interface CatalogueSearchProps {
    products: Paginated<CatalogueRow>;
    filters: { department: string | null };
    departments: DepartmentCount[];
    yourDepartments: YourDepartment[];
    hasVatRates: boolean;
    priceRule: { mode: 'rrp' | 'margin'; margin: number };
    sharing: boolean;
}

/** StarterPackPage::for() */
export interface StarterPackProps {
    packs: { value: string; label: string; description: string }[];
    pack: string;
    suggested: string;
    departments: (DepartmentCount & { included: boolean })[];
    owned: number;
    productCount: number;
    yourDepartments: YourDepartment[];
    hasVatRates: boolean;
}

export interface PriceRuleValues {
    price_rule: 'rrp' | 'margin';
    margin: string;
    end_in_9: boolean;
}

/** BarcodeLookup::find() */
export interface LookupResult {
    found: boolean;
    existing: { id: string; name: string } | null;
    product:
        | (MasterRow & {
              departmentId: string | null;
              categoryId: string | null;
              vatRateId: string | null;
              volumeMl: string | null;
              netMassKg: string | null;
          })
        | null;
}

/** The price PriceRule (PHP) gives a product, for the review step's preview. Null = no price. */
export function previewPrice(rule: PriceRuleValues, typed: string, cost: string, rrp: string | null, vatPercent: string | null): string | null {
    if (typed !== '' && Number(typed) > 0) {
        return Number(typed).toFixed(2);
    }
    const margin = Number(rule.margin);
    if (rule.price_rule === 'margin' && cost !== '' && Number(cost) > 0 && margin < 100) {
        const net = Number(cost) / (1 - margin / 100);
        let pence = Math.ceil(Math.round(net * (1 + Number.parseFloat(vatPercent ?? '0') / 100) * 1000000) / 10000);
        if (rule.end_in_9 && pence % 10 !== 9) {
            pence = Math.floor(pence / 10) * 10 + 9;
        }

        return (pence / 100).toFixed(2);
    }

    return rrp && Number(rrp) > 0 ? Number(rrp).toFixed(2) : null;
}
