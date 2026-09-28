import { BranchLicenceDialog } from '@/components/admin/licences/branch-licence-dialog';
import { describeLicence } from '@/components/admin/licences/licence-form-fields';
import { type LicenceOptions } from '@/components/admin/licences/types';
import { type TenantBranch } from '@/components/admin/tenants/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { KeyRound, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

interface BranchLicenceStripProps {
    tenantId: string;
    branch: TenantBranch;
    options: LicenceOptions;
    canManage: boolean;
}

/** "Tills 2 of 3 in use · Trial · 1 year · 5 features" under a branch, with its licence settings dialog (module 1.11). */
export function BranchLicenceStrip({ tenantId, branch, options, canManage }: BranchLicenceStripProps) {
    const [open, setOpen] = useState(false);
    const licence = branch.licence;
    const full = licence.keysInUse >= licence.maxRegisters;
    const featureCount = licence.features === null ? null : licence.features.length;

    return (
        <div className="bg-subtle flex flex-col gap-2 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px]">
                <span className="inline-flex items-center gap-1.5 font-medium">
                    <KeyRound className="text-muted-foreground size-3.5" aria-hidden />
                    <span className="tabular-nums">
                        {licence.keysInUse} of {licence.maxRegisters}
                    </span>{' '}
                    till keys in use
                    {full && <Badge variant="warning">Full</Badge>}
                </span>
                <span className="text-muted-foreground">{licence.keysActivated} activated</span>
                <span className="text-muted-foreground">{describeLicence(licence)}</span>
                <span className="text-muted-foreground">
                    {featureCount === null ? 'Plan features' : `${featureCount} ${featureCount === 1 ? 'feature' : 'features'}`}
                </span>
            </div>
            {canManage && (
                <Button variant="outline" size="sm" className="self-start sm:self-auto" onClick={() => setOpen(true)}>
                    <SlidersHorizontal />
                    Licence settings
                </Button>
            )}
            <BranchLicenceDialog open={open} onOpenChange={setOpen} tenantId={tenantId} branch={branch} options={options} />
        </div>
    );
}
