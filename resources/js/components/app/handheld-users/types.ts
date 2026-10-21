import { formatDate } from '@/lib/country';
import { relativeTime } from '@/lib/relative-time';

/** Matches `Handheld\Queries\HandheldUsersPage::handle()`. */
export interface HandheldUser {
    id: string;
    name: string;
    email: string;
    shopId: string;
    shopName: string | null;
    shopOpen: boolean;
    isActive: boolean;
    canChangePrices: boolean;
    devices: number;
    lastLoginAt: string | null;
    lastUsedAt: string | null;
    createdAt: string | null;
    createdBy: string | null;
}

export interface HandheldShop {
    id: string;
    name: string;
    code: string;
}

export interface HandheldUsersProps {
    users: HandheldUser[];
    shops: HandheldShop[];
    stats: { active: number; inactive: number; canChangePrices: number; devices: number };
    /** The business's plan includes the handheld app (owner 2026-10-21: monthly and yearly plans). */
    included: boolean;
}

export type HandheldCommand = { kind: 'edit' | 'password' | 'sign-out' | 'deactivate' | 'reactivate'; user: HandheldUser };

/** "2h ago" for recent use, else the date; "Never" when the user has not signed in. */
export function lastSeen(user: HandheldUser): string {
    const at = user.lastUsedAt ?? user.lastLoginAt;

    if (!at) {
        return 'Never';
    }

    const days = (Date.now() - new Date(at).getTime()) / 86_400_000;

    return days < 7 ? relativeTime(at) : formatDate(at, { day: 'numeric', month: 'short', year: 'numeric' });
}
