import { daysUntil, type LicenceKind, londonDate } from '@/components/app/shops/types';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { cn } from '@/lib/utils';

/** Trial or paid licence for a shop's tills. */
export function LicenceKindPill({ kind }: { kind: LicenceKind }) {
    return <StatusPill tone={kind === 'trial' ? 'info' : 'neutral'}>{kind === 'trial' ? 'Trial' : 'Paid'}</StatusPill>;
}

/** "Renews 7 Oct 2026 · in 12 days", amber within 14 days, red once past. */
export function EndsAtText({ iso, prefix = 'Ends', className }: { iso: string | null; prefix?: string; className?: string }) {
    const days = daysUntil(iso);
    if (!iso || days === null) {
        return <span className={cn('text-muted-foreground', className)}>No end date yet</span>;
    }
    const tone = days < 0 ? 'text-danger-foreground' : days <= 14 ? 'text-warning-foreground' : 'text-muted-foreground';
    const when = days < 0 ? `${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'} ago` : days === 0 ? 'today' : `in ${days} ${days === 1 ? 'day' : 'days'}`;

    return (
        <span className={cn(tone, className)}>
            {prefix} {londonDate(iso)} · {when}
        </span>
    );
}

/** The effective licence status of a till (what the till is told). */
export function LicenceStatusBadge({ status, label }: { status: string; label: string }) {
    return <StatusBadge status={status} label={label} />;
}

/** Shops | Business details link tabs. */
export function ShopsTabs({ active }: { active: 'shops' | 'business' }) {
    return (
        <PageTabs
            label="Shops and tills sections"
            tabs={[
                { label: 'Shops', href: route('app.shops.index'), active: active === 'shops' },
                { label: 'Business details', href: route('app.shops.business'), active: active === 'business' },
            ]}
        />
    );
}
