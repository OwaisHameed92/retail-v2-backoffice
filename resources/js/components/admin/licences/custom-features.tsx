import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { RotateCcw, SlidersHorizontal } from 'lucide-react';

/** A branch whose own features differ from its plan's (fix 2026-10-07): it no longer follows plan edits. */
export function CustomFeaturesBadge() {
    return (
        <Badge variant="warning" title="This shop's tills carry their own features, not the plan's. Plan changes do not reach them.">
            <SlidersHorizontal aria-hidden />
            Custom features (differ from the plan)
        </Badge>
    );
}

interface UsePlanFeaturesButtonProps {
    tenantId: string;
    branchId: string;
    branchName: string;
}

/** "Use the plan's features": drops the shop's own list so it follows the plan again (re-signs its keys). */
export function UsePlanFeaturesButton({ tenantId, branchId, branchName }: UsePlanFeaturesButtonProps) {
    return (
        <ConfirmDialog
            trigger={
                <Button type="button" variant="ghost" size="sm">
                    <RotateCcw />
                    Use the plan&apos;s features
                </Button>
            }
            title={`Use the plan's features in ${branchName}?`}
            description="The shop's own feature list is removed and its tills get the plan's features at their next check-in. From then on it follows the plan."
            confirmLabel="Use the plan's features"
            onConfirm={() =>
                new Promise((resolve) =>
                    router.delete(route('admin.tenants.branches.licence.features.reset', [tenantId, branchId]), {
                        preserveScroll: true,
                        onFinish: () => resolve(null),
                    }),
                )
            }
        />
    );
}

/** On the licence page: the badge, and the reset for staff who manage licences. */
export function BranchCustomFeatures({
    tenantId,
    branch,
    canManage,
}: {
    tenantId: string;
    branch: { id: string; name: string };
    canManage: boolean;
}) {
    return (
        <div className="mt-2 flex flex-wrap items-center gap-2">
            <CustomFeaturesBadge />
            {canManage && <UsePlanFeaturesButton tenantId={tenantId} branchId={branch.id} branchName={branch.name} />}
        </div>
    );
}
