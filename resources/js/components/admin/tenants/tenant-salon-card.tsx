import { type TenantSalonSummary } from '@/components/admin/tenants/types';
import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/country';

/** Salon W3b: read-only salon counts on the admin tenant page (salon businesses only, while SALON_ONLINE is on). */
export function TenantSalonCard({ salon }: { salon: TenantSalonSummary }) {
    const number = (value: number) => <span className="tabular-nums">{value.toLocaleString('en-GB')}</span>;

    return (
        <SectionCard
            title={
                <span className="inline-flex items-center gap-2">
                    Salon
                    <Badge variant="info">Salon / Barber</Badge>
                </span>
            }
            description="What the salon tills send and the portal serves. Read only; the business manages it under Salon in their portal."
        >
            <DescriptionList
                columns={4}
                items={[
                    { label: 'Salon shops', value: number(salon.salonShops) },
                    { label: 'Stylists on the tills', value: number(salon.stylists) },
                    { label: 'Calendar feeds on', value: number(salon.feedsOn) },
                    {
                        label: 'Online requests this week',
                        value: (
                            <span className="inline-flex flex-wrap items-center gap-1.5">
                                {number(salon.requestsThisWeek)}
                                <span className="text-muted-foreground text-xs">since {formatDate(salon.weekStartsOn, { year: undefined })}</span>
                                {salon.pendingRequests > 0 && <Badge variant="warning">{salon.pendingRequests} waiting</Badge>}
                            </span>
                        ),
                    },
                ]}
            />
        </SectionCard>
    );
}
