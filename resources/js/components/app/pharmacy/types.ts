/** Matches App\Domain\Pharmacy\Queries\*, App\Domain\Parcels\Queries\ParcelActivity and their controllers (module 5.10). */
import { type CashPageProps, type Option, type Page } from '@/components/app/cash/types';

export type ChargeStatus = 'paid' | 'exempt' | 'private';
export type MedicineClass = 'generalSale' | 'pharmacyOnly' | 'prescriptionOnly';

export interface DispensingRow {
    id: string;
    dispensedAt: string | null;
    shop: string | null;
    prescriber: string | null;
    prescriberRegistration: string | null;
    prescriptionDate: string | null;
    dispensedBy: string | null;
    chargeStatus: ChargeStatus | null;
    exemption: string | null;
    chargeAmount: string;
    items: number;
    hasSale: boolean;
}

export interface DispensingProps extends CashPageProps {
    records: Page<DispensingRow>;
    summary: { records: number; items: number; paid: number; exempt: number; private: number; nhsCharges: string; privateCharges: string };
    exemptions: { exemption: string; count: number }[];
    shops: { shop: string; records: number; paid: number; exempt: number; private: number; charges: string }[];
    periods: { unit: 'day' | 'week'; rows: { period: string; records: number; paid: number; exempt: number; private: number }[] };
    charge: ChargeStatus | null;
    exemption: string | null;
}

export interface MedicineRow {
    id: string;
    productId: string;
    product: string;
    sku: string | null;
    class: MedicineClass | null;
    note: string | null;
    updatedAt: string | null;
}

export interface MedicinesProps {
    rows: Page<MedicineRow>;
    counts: { class: MedicineClass; count: number }[];
    class: MedicineClass | null;
    candidates: { id: string; name: string; sku: string | null; class: MedicineClass | null }[];
    canEdit: boolean;
}

export type ParcelDirection = 'dropOff' | 'collection';
export type ParcelStatus = 'open' | 'handedOver';

export interface ParcelRow {
    id: string;
    trackingCode: string;
    customer: string | null;
    carrier: string | null;
    shop: string | null;
    direction: ParcelDirection | null;
    status: ParcelStatus | null;
    registeredAt: string | null;
    handedOverAt: string | null;
    idCheck: string | null;
    waitingDays: number | null;
}

export interface ParcelsProps extends CashPageProps {
    parcels: Page<ParcelRow>;
    summary: { registered: number; dropOffs: number; collections: number; handedOver: number; waiting: number; waitingLong: number; waitingDays: number };
    carriers: { id: string; name: string; shop: string | null; isActive: boolean; dropOffs: number; collections: number; handedOver: number; waiting: number }[];
    carrierOptions: Option[];
    only: { carrier: string | null; direction: ParcelDirection | null; status: ParcelStatus | null };
}
