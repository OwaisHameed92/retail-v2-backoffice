import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Eye, LoaderCircle, Undo2 } from 'lucide-react';
import { useState } from 'react';

export interface ImpersonationData {
    userName: string;
    userEmail: string;
    companyName: string;
    adminName: string | null;
}

/**
 * Persistent banner while a Switch & Save admin is logged in as a customer ("Login as customer").
 * "Return to admin" ends it and restores the admin session.
 */
export function AppImpersonationBanner() {
    const { impersonation } = usePage<SharedData & { impersonation: ImpersonationData | null }>().props;
    const [busy, setBusy] = useState(false);

    if (!impersonation) {
        return null;
    }

    const stop = () => router.post(route('admin.impersonation.stop'), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <div
            role="status"
            className="bg-primary text-primary-foreground flex min-h-10 flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-1.5 text-[13px]"
        >
            <Eye className="size-4 shrink-0" aria-hidden />
            <p className="text-center">
                Viewing as <strong className="font-semibold">{impersonation.companyName}</strong>
                <span className="hidden opacity-80 sm:inline"> ({impersonation.userEmail})</span>
            </p>
            <button
                type="button"
                onClick={stop}
                disabled={busy}
                className="bg-primary-foreground/15 hover:bg-primary-foreground/25 focus-visible:ring-primary-foreground inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 font-medium focus-visible:ring-2 focus-visible:outline-none disabled:opacity-70"
            >
                {busy ? <LoaderCircle className="size-3.5 animate-spin" aria-hidden /> : <Undo2 className="size-3.5" aria-hidden />}
                Return to admin
            </button>
        </div>
    );
}
