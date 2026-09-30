/** Matches App\Domain\Accounts\Queries\* and AccountsController (module 5.5). Money is a 2 dp string. */
import { type TableMeta } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

export interface AccountsFiltersState {
    from: string;
    to: string;
    shop: string | null;
    account: string | null;
    refType: string | null;
    fix: boolean;
    shopLocked: boolean;
}

export interface AccountsPageProps {
    filters: AccountsFiltersState;
    options: { shops: Option[] };
}

export interface Page<T> {
    data: T[];
    meta: TableMeta;
}

/** Refund entries posted before the till's 0.1.15 fix, in the chosen dates. */
export interface RefundFixSummary {
    entries: number;
    sales: string;
}

export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export interface ChartRow {
    code: string;
    name: string;
    type: AccountType;
    parentCode: string | null;
    vatBox: number | null;
    isSystem: boolean;
    isActive: boolean;
    isDebitBalance: boolean;
    shops: number;
    movement: string;
    balance: string;
    hasLines: boolean;
}

export interface ChartProps extends AccountsPageProps {
    accounts: ChartRow[];
    types: { type: AccountType; count: number }[];
    shopCount: number;
}

export interface JournalRow {
    id: string;
    date: string | null;
    refType: string | null;
    refId: string | null;
    memo: string | null;
    shop: string | null;
    till: string | null;
    postedAt: string | null;
    debits: string | null;
    credits: string | null;
    isReversed: boolean;
    isReversal: boolean;
    oldRefund: boolean;
}

export interface JournalsProps extends AccountsPageProps {
    entries: Page<JournalRow>;
    refTypes: Option[];
    accounts: Option[];
    refundFix: RefundFixSummary;
}

export interface JournalLineRow {
    id: string;
    code: string;
    name: string;
    debit: string | null;
    credit: string | null;
    memo: string | null;
}

export interface JournalProps {
    entry: JournalRow & {
        postedBy: string | null;
        reversesEntryId: string | null;
        reversedByEntryId: string | null;
        saleId: string | null;
        lines: JournalLineRow[];
    };
}

export interface TrialBalanceRow {
    code: string;
    name: string;
    type: AccountType;
    periodDebit: string;
    periodCredit: string;
    debit: string | null;
    credit: string | null;
}

export interface TrialBalanceProps extends AccountsPageProps {
    rows: TrialBalanceRow[];
    totals: { periodDebit: string; periodCredit: string; debit: string; credit: string; difference: string };
    balanced: boolean;
    refundFix: RefundFixSummary;
}

export interface StatementLine {
    code: string;
    name: string;
    amount: string;
}

export interface ProfitAndLossProps extends AccountsPageProps {
    income: StatementLine[];
    costOfSales: StatementLine[];
    overheads: StatementLine[];
    totals: { income: string; costOfSales: string; grossProfit: string; overheads: string; netProfit: string };
    salesCheck: { journals: string; salesData: string; difference: string; differs: boolean };
    refundFix: RefundFixSummary;
}

export interface BalanceSheetProps extends AccountsPageProps {
    assets: StatementLine[];
    liabilities: StatementLine[];
    equity: StatementLine[];
    totals: { assets: string; liabilities: string; equity: string; profit: string; netAssets: string; capital: string; difference: string };
    balanced: boolean;
    refundFix: RefundFixSummary;
}

export interface ExpenseRow {
    id: string;
    date: string | null;
    shop: string | null;
    payee: string | null;
    reason: string | null;
    receiptRef: string | null;
    vatReceipt: boolean;
    net: string | null;
    vat: string | null;
    vatRate: string | null;
    gross: string | null;
    paidBy: 'cash' | 'card' | 'bank' | 'owner' | null;
    note: string | null;
    voided: boolean;
    voidReason: string | null;
}

export interface ExpensesProps extends AccountsPageProps {
    expenses: Page<ExpenseRow>;
    totals: { count: number; net: string; vat: string; gross: string; reclaimableVat: string; unreclaimedVat: string };
}

export interface VatBox {
    box: number;
    label: string;
    amount: string;
}

export interface VatSource {
    key: string;
    label: string;
    net: string;
    vat: string;
    count: number | null;
    boxes: string;
}

export interface TillVatReturn {
    id: string;
    shop: string | null;
    from: string | null;
    to: string | null;
    scheme: string | null;
    boxes: (string | null)[];
    filedAt: string | null;
}

export interface VatData {
    quarter: { value: string; label: string; from: string; to: string; stagger: number };
    quarters: Option[];
    boxes: VatBox[];
    position: 'pay' | 'reclaim';
    sources: VatSource[];
    unreclaimedVat: string;
    rates: { code: string; percentage: string; net: string; vat: string; gross: string }[];
    tillReturns: TillVatReturn[];
}

export type VatProps = AccountsPageProps & VatData;

export interface VatPrintProps extends VatData {
    business: string;
    shopName: string;
    filters: AccountsFiltersState;
}

export interface FixedAssetRow {
    id: string;
    name: string;
    code: string | null;
    category: string | null;
    shop: string | null;
    purchaseDate: string | null;
    cost: string | null;
    usefulLifeYears: number | null;
    depreciationRate: string | null;
    residual: string | null;
    disposalDate: string | null;
    disposalProceeds: string | null;
    isActive: boolean;
    notes: string | null;
}

export interface FixedAssetsProps extends AccountsPageProps {
    assets: Page<FixedAssetRow>;
    totals: { held: number; cost: string };
}
