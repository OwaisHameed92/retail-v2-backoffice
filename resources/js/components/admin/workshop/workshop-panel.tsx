import { type WorkshopTenantData } from '@/components/admin/workshop/types';
import { WorkshopBranchCard } from '@/components/admin/workshop/workshop-branch-card';
import { EmptyState } from '@/components/shared/empty-state';
import { Wrench } from 'lucide-react';

/** The tenant page's "Workshop (BikeBook)" tab (W5): one card per shop. Shown only while the connector is switched on. */
export function WorkshopPanel({ tenantId, workshop }: { tenantId: string; workshop: WorkshopTenantData }) {
    if (workshop.branches.length === 0) {
        return <EmptyState icon={Wrench} title="No shops yet" body="Add a shop first. BikeBook connects to one shop at a time." bordered />;
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="text-muted-foreground text-sm">
                BikeBook connects to one shop at a time. Tick the add-on, then the owner approves BikeBook from BikeBook, or you create an API key for
                BikeBook. Direct mode on the till needs none of this.
            </p>
            {workshop.branches.map((card) => (
                <WorkshopBranchCard key={card.branchId} tenantId={tenantId} card={card} canManage={workshop.canManage} />
            ))}
        </div>
    );
}
