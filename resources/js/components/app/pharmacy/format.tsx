import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import AppLayout from '@/layouts/app-layout';
import { dateLocale, zonedDateFormat } from '@/lib/country';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { type ChargeStatus, type MedicineClass } from './types';

export const CHARGE: Record<ChargeStatus, { label: string; tone: StatusTone }> = {
    paid: { label: 'NHS charge paid', tone: 'success' },
    exempt: { label: 'Exempt', tone: 'info' },
    private: { label: 'Private', tone: 'violet' },
};

export const EXEMPTIONS: Record<string, string> = {
    none: 'No exemption',
    aged60OrOver: 'Aged 60 or over',
    under16: 'Under 16',
    aged16To18InEducation: 'Aged 16–18 in education',
    maternityExemption: 'Maternity exemption',
    medicalExemption: 'Medical exemption',
    prepaymentCertificate: 'Prepayment certificate',
    warPension: 'War pension',
    lowIncomeHc2: 'Low income (HC2)',
    benefits: 'Benefits',
    taxCredit: 'Tax credit',
    freeInNation: 'Free in this nation',
};

export const CLASSES: Record<MedicineClass, { label: string; short: string; tone: StatusTone; help: string }> = {
    generalSale: { label: 'General sale', short: 'GSL', tone: 'neutral', help: 'Can be sold without a pharmacist.' },
    pharmacyOnly: { label: 'Pharmacy only', short: 'P', tone: 'warning', help: 'Sold only with a pharmacist present.' },
    prescriptionOnly: { label: 'Prescription only', short: 'POM', tone: 'danger', help: 'Supplied only against a prescription.' },
};

export function ChargePill({ status }: { status: ChargeStatus | null }) {
    return status ? <StatusPill tone={CHARGE[status].tone}>{CHARGE[status].label}</StatusPill> : <span className="text-muted-foreground">—</span>;
}

export function ClassPill({ value }: { value: MedicineClass | null }) {
    return value ? (
        <StatusPill tone={CLASSES[value].tone}>
            {CLASSES[value].label} ({CLASSES[value].short})
        </StatusPill>
    ) : (
        <span className="text-muted-foreground">Not classified</span>
    );
}

const dateTime = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** "29 Sept 2026, 10:00" (shop time) from an ISO UTC instant. */
export function shopDateTime(iso: string | null): string {
    return iso ? dateTime().format(new Date(iso)) : '—';
}

/** The Pharmacy frame: header, the Dispensing | Medicine classes tabs, then the page. */
export function PharmacyPageLayout({
    tab,
    title,
    description,
    children,
}: {
    tab: 'dispensing' | 'medicines';
    title: string;
    description: ReactNode;
    children: ReactNode;
}) {
    return (
        <AppLayout>
            <Head title={`${title} · Pharmacy`} />
            <PageHeader
                title="Pharmacy"
                description={description}
                tabs={
                    <PageTabs
                        label="Pharmacy sections"
                        tabs={[
                            { label: 'Dispensing', href: route('app.pharmacy.dispensing'), active: tab === 'dispensing' },
                            { label: 'Medicine classes', href: route('app.pharmacy.medicines'), active: tab === 'medicines' },
                        ]}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}
