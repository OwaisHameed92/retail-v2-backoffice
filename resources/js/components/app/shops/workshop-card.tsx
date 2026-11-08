import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { dateLocale, zonedDateFormat } from '@/lib/country';

export interface ShopWorkshopCard {
    addOn: boolean;
    status: 'notConnected' | 'pending' | 'connected' | 'failed' | 'disconnected';
    statusLabel: string;
    connectedAt: string | null;
    lastCallAt: string | null;
    failing: boolean;
    counts: { productsInScope: number; customers: number; openOrders: number; failedWebhooks: number } | null;
}

/** "8 Nov 2026, 09:41" in the shop's time zone. */
function formatDateTime(iso: string | null): string {
    return iso
        ? zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
        : '—';
}

const TONES = { notConnected: 'neutral', pending: 'warning', connected: 'success', failed: 'danger', disconnected: 'neutral' } as const;

/** W5: the shop's read-only "Bike workshop (BikeBook)" card. Shown only while the connector is switched on. */
export function WorkshopCard({ workshop }: { workshop: ShopWorkshopCard }) {
    const rows: [string, string][] = workshop.addOn
        ? [
              ['Connected since', workshop.status === 'connected' || workshop.status === 'failed' ? formatDateTime(workshop.connectedAt) : '—'],
              ['Last BikeBook call', workshop.lastCallAt ? formatDateTime(workshop.lastCallAt) : '—'],
              ['Products BikeBook sees', workshop.counts ? workshop.counts.productsInScope.toLocaleString('en-GB') : '—'],
              ['Open workshop orders', workshop.counts ? workshop.counts.openOrders.toLocaleString('en-GB') : '—'],
          ]
        : [];

    return (
        <SectionCard
            title="Bike workshop (BikeBook)"
            description={workshop.addOn ? 'BikeBook sends repair jobs to this shop’s till. Switch & Save sets the connection up.' : undefined}
        >
            <div className="grid gap-4 text-sm">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-muted-foreground">Status</span>
                    <StatusBadge
                        status={workshop.status}
                        label={workshop.addOn ? workshop.statusLabel : 'Not included'}
                        tone={workshop.addOn ? TONES[workshop.status] : 'neutral'}
                    />
                </div>
                {workshop.addOn ? (
                    <dl className="grid gap-3">
                        {rows.map(([label, value]) => (
                            <div key={label} className="flex items-center justify-between gap-3">
                                <dt className="text-muted-foreground">{label}</dt>
                                <dd className="text-right font-medium tabular-nums">{value}</dd>
                            </div>
                        ))}
                    </dl>
                ) : (
                    <p className="text-muted-foreground">Workshop integration is not in this shop’s licence. Contact Switch & Save to add it.</p>
                )}
                {workshop.failing && <p className="text-danger-foreground">BikeBook is not answering our updates. Switch & Save has been told.</p>}
            </div>
        </SectionCard>
    );
}
