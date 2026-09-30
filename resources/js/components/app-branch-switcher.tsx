import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Store } from 'lucide-react';
import { useState } from 'react';

export interface BranchOption {
    id: string;
    code: string;
    name: string;
}

export interface BranchSharedData extends SharedData {
    branches: BranchOption[];
    currentBranchId: string | null;
    /** A user limited to one shop (module 3.3): the switcher shows it and cannot change. */
    branchLocked?: boolean;
}

const ALL = 'all';

/**
 * Top-bar branch filter: "All branches" or one active branch of the current company. The choice is kept in
 * the session (`current_branch_id`) and shared as `currentBranchId`; pages filter their data by it. A user limited to
 * one shop (`branchLocked`) sees only that shop.
 */
export function AppBranchSwitcher() {
    const { branches, currentBranchId, branchLocked } = usePage<BranchSharedData>().props;
    const [busy, setBusy] = useState(false);
    const value = currentBranchId ?? ALL;

    const change = (next: string) => {
        if (next === value) {
            return;
        }
        router.post(
            route('app.branch.switch'),
            { branch_id: next === ALL ? null : next },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    return (
        <Select value={value} onValueChange={change} disabled={busy || branchLocked || !branches || branches.length === 0}>
            <SelectTrigger className="h-9 w-[160px]" aria-label="Branch">
                <Store className="text-muted-foreground size-4" aria-hidden />
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {!branchLocked && <SelectItem value={ALL}>All branches</SelectItem>}
                {!branchLocked && branches && branches.length > 0 && <SelectSeparator />}
                {branches?.map((branch) => (
                    <SelectItem key={branch.id} value={branch.id}>
                        {branch.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
