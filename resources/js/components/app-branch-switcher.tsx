import { type SharedData } from '@/types';

export interface BranchOption {
    id: string;
    code: string;
    name: string;
}

/**
 * The portal's "current shop" (session `current_branch_id`, shared as `currentBranchId`). There is no global
 * switcher any more (module 7.1): pages that honour the current shop show a Shop select in their own filters bar
 * (e.g. `BusinessFiltersBar` posts `app.branch.switch`), and list pages default their shop filter to it.
 */
export interface BranchSharedData extends SharedData {
    branches: BranchOption[];
    currentBranchId: string | null;
    /** A user limited to one shop (module 3.3): shop selects show it and cannot change. */
    branchLocked?: boolean;
}
