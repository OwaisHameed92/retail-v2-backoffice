/** Module 4.7 (Shops and tills). Matches App\Domain\Shops\Queries\{ShopsOverview, ShopDetail, BusinessPage, ShopRequests}. */
import { type HealthThresholds, type ShopHealth, type TillHealth } from '@/components/till-health/types';
import { zonedDateFormat } from '@/lib/country';

export type LicenceKind = 'trial' | 'full';
export type ShopRequestKind = 'moreTills' | 'newShop';

/** ShopsOverview shop row. */
export interface ShopRow {
    id: string;
    name: string;
    code: string;
    isActive: boolean;
    address: string | null;
    phone: string | null;
    tillsActive: number;
    tillsAllowed: number;
    licence: {
        kind: LicenceKind;
        statuses: { status: string; label: string; count: number }[];
        nextEndsAt: string | null;
        needsAttention: boolean;
    };
    health: ShopHealth | null;
}

/** ShopRequests::recent(). */
export interface ShopRequestRow {
    id: string;
    kind: ShopRequestKind;
    kindLabel: string;
    tills: number;
    shop: { id: string; name: string; code: string } | null;
    newShopName: string | null;
    message: string | null;
    requestedBy: string | null;
    count: number;
    sentAt: string | null;
    lastAskedAt: string | null;
    done: boolean;
    doneAt: string | null;
}

export interface RequestOptions {
    shops: { id: string; name: string; code: string }[];
    maxTills: number;
    canAskForShop: boolean;
}

export interface ShopsIndexProps {
    shops: ShopRow[];
    summary: {
        shops: number;
        shopsAllowed: number;
        tills: number;
        tillsAllowed: number;
        tillsOnline: number;
        nextEndsAt: string | null;
        attention: number;
    };
    requests: ShopRequestRow[];
    requestOptions: RequestOptions;
    can: { manage: boolean; manageBusiness: boolean };
    restricted: boolean;
    thresholds: HealthThresholds;
}

/** TillLicenceView::of(): read only, the key's last 4 only. */
export interface TillLicence {
    id: string;
    status: string;
    statusLabel: string;
    reason: string | null;
    canTrade: boolean;
    isTrial: boolean;
    endsAt: string | null;
    graceEndsAt: string | null;
    activatedAt: string | null;
    activateBy: string | null;
    lastValidatedAt: string | null;
    appVersion: string | null;
    maskedKey: string;
    plan: string | null;
    features: { value: string; label: string }[];
}

export interface TillRow {
    id: string;
    name: string;
    code: string;
    isMainTill: boolean;
    isActive: boolean;
    licence: TillLicence | null;
    health: TillHealth | null;
}

export type ShopForm = {
    name: string;
    address: string;
    town: string;
    postcode: string;
    phone: string;
    vat_number: string;
    receipt_footer: string;
};

export interface ShopShowProps {
    shop: {
        id: string;
        name: string;
        code: string;
        isActive: boolean;
        nation: string;
        address: string | null;
        town: string | null;
        postcode: string | null;
        phone: string | null;
        vatNumber: string | null;
        receiptFooter: string | null;
        createdAt: string | null;
    };
    business: { name: string; vatNumber: string | null; receiptFooter: string | null };
    licence: {
        kind: LicenceKind;
        tillsAllowed: number;
        tillsActive: number;
        features: { value: string; label: string }[];
        nextEndsAt: string | null;
    };
    tills: TillRow[];
    health: ShopHealth | null;
    thresholds: HealthThresholds;
    requests: ShopRequestRow[];
    requestOptions: RequestOptions;
    can: { edit: boolean; ask: boolean };
}

export type BusinessForm = {
    name: string;
    legal_name: string;
    vat_number: string;
    company_number: string;
    address: string;
    town: string;
    postcode: string;
    phone: string;
    email: string;
    receipt_footer: string;
};

export interface BusinessPageProps {
    business: { [K in keyof BusinessForm]: K extends 'name' ? string : string | null };
    facts: { status: string; businessType: string | null; shops: number; shopsAllowed: number; customerSince: string | null };
    can: { edit: boolean };
}

const dateFormat = () => zonedDateFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/** "7 Oct 2026" in shop time, or the fallback. */
export function shopDate(iso: string | null | undefined, fallback = ''): string {
    return iso ? dateFormat().format(new Date(iso)) : fallback;
}

/** Days from now to the date (negative when past). */
export function daysUntil(iso: string | null | undefined): number | null {
    return iso ? Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000) : null;
}

/** Null → '' for form defaults. */
export function blankNulls<T extends Record<string, string | null>>(values: T): { [K in keyof T]: string } {
    return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value ?? ''])) as { [K in keyof T]: string };
}
