import { type AdminRecord } from '@/components/admin/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

/** Deactivate (with confirmation) or reactivate an admin user. */
export function AdminStatusDialog({ admin, disabled = false }: { admin: AdminRecord; disabled?: boolean }) {
    const [open, setOpen] = useState(false);
    const { post, processing } = useForm({});

    const deactivate: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.admins.deactivate', admin.id), {
            preserveScroll: true,
            onFinish: () => setOpen(false),
        });
    };

    if (!admin.isActive) {
        return (
            <Button
                variant="ghost"
                size="sm"
                disabled={processing}
                onClick={() => post(route('admin.admins.reactivate', admin.id), { preserveScroll: true })}
            >
                Reactivate
            </Button>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" disabled={disabled} className="text-destructive hover:text-destructive/80">
                    Deactivate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Deactivate {admin.name}?</DialogTitle>
                <DialogDescription>
                    {admin.name} will be signed out and will not be able to log in to the admin area. You can reactivate them later.
                </DialogDescription>
                <form onSubmit={deactivate}>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Deactivate
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
