import { dateLocale, zonedDateFormat } from '@/lib/country';
import { type CompanyRole } from '@/types';

/** Matches `PortalUsers\Data\PortalUsersPage::for()` (module 4.1). */
export interface PortalMember {
    id: number;
    name: string;
    email: string;
    role: CompanyRole;
    roleLabel: string;
    branchId: string | null;
    branchName: string | null;
    isActive: boolean;
    isYou: boolean;
    joinedAt: string | null;
}

export interface PortalInvitation {
    id: string;
    name: string;
    email: string;
    role: CompanyRole;
    roleLabel: string;
    branchName: string | null;
    status: 'pending' | 'expired';
    invitedBy: string | null;
    sentAt: string | null;
    expiresAt: string;
    sendCount: number;
}

export interface RoleOption {
    value: CompanyRole;
    label: string;
    help: string;
    canLimitToShop: boolean;
}

export interface MatrixRow {
    key: string;
    group: string;
    label: string;
    roles: Record<CompanyRole, boolean>;
}

export interface ShopOption {
    id: string;
    name: string;
    code: string;
}

export interface PortalUsersProps {
    members: PortalMember[];
    invitations: PortalInvitation[];
    stats: { active: number; owners: number; oneShop: number; deactivated: number; pendingInvitations: number };
    roles: RoleOption[];
    matrix: MatrixRow[];
    branches: ShopOption[];
    validDays: number;
}

/** "Every shop" in the shop picker (the form sends an empty branch_id). */
export const EVERY_SHOP = 'all';

const dateFormat = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric' });

export function formatDate(iso: string | null): string {
    return iso ? dateFormat().format(new Date(iso)) : 'Not known';
}
