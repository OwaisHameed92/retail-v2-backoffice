import { type AdminRecord } from '@/components/admin/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { router } from '@inertiajs/react';

/** Runs a request and settles when Inertia finishes, so ConfirmDialog shows its spinner meanwhile. */
function post(url: string): Promise<void> {
    return new Promise((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));
}

interface AdminStatusDialogProps {
    admin: AdminRecord;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Confirm deactivating (or reactivating) an admin user. Opened from the row's actions menu. */
export function AdminStatusDialog({ admin, open, onOpenChange }: AdminStatusDialogProps) {
    if (admin.isActive) {
        return (
            <ConfirmDialog
                open={open}
                onOpenChange={onOpenChange}
                title={`Deactivate ${admin.name}?`}
                description={`${admin.name} will be signed out and will not be able to log in to the admin area. You can reactivate them later.`}
                confirmLabel="Deactivate"
                destructive
                onConfirm={() => post(route('admin.admins.deactivate', admin.id))}
            />
        );
    }

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Reactivate ${admin.name}?`}
            description={`${admin.name} will be able to log in to the admin area again with their existing password.`}
            confirmLabel="Reactivate"
            onConfirm={() => post(route('admin.admins.reactivate', admin.id))}
        />
    );
}
