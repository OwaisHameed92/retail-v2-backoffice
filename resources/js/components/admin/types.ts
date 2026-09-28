import { type SharedData } from '@/types';

export type AdminRoleValue = 'owner' | 'sales' | 'support' | 'accounts';

/** The signed-in admin, shared with every admin page by ShareAdminInertiaData. */
export interface AdminSession {
    id: string;
    name: string;
    email: string;
    role: AdminRoleValue;
    roleLabel: string;
    abilities: string[];
}

export interface AdminSharedData extends SharedData {
    admin: AdminSession & {
        /** Optional sidebar counts (e.g. `{ leads: 3 }`). Not shared by the backend yet; the pill shows when present. */
        navCounts?: Record<string, number>;
    };
}

/** An admin row as shaped by App\Domain\Admin\Data\AdminData::fromModel(). */
export interface AdminRecord {
    id: string;
    name: string;
    email: string;
    role: AdminRoleValue;
    roleLabel: string;
    isActive: boolean;
    lastLoginAt: string | null;
    createdAt: string | null;
}

export interface RoleOption {
    value: AdminRoleValue;
    label: string;
}
