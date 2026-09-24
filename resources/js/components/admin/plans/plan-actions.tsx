import { showToast } from '@/components/shared/toaster';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/react';
import { Archive, ArchiveRestore, Copy, Eye, MoreHorizontal, Pencil } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface PlanRef {
    id: string;
    name: string;
    status: string;
}

type Kind = 'archive' | 'restore' | 'duplicate';

/** Runs a lifecycle request and settles when Inertia finishes, so ConfirmDialog can show its spinner. */
function send(kind: Kind, plan: PlanRef): Promise<void> {
    const url = route(`admin.plans.${kind}`, plan.id);

    return new Promise((resolve) => {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                showToast(errors.plan ?? Object.values(errors)[0] ?? 'Something went wrong. Try again.', 'error'),
            onFinish: () => resolve(),
        };

        if (kind === 'archive') {
            router.delete(url, options);
        } else {
            router.post(url, {}, options);
        }
    });
}

function dialogCopy(kind: Kind, plan: PlanRef) {
    switch (kind) {
        case 'archive':
            return {
                title: `Archive ${plan.name}?`,
                description: `${plan.name} will no longer be offered or shown in the plan list. Its settings and history are kept, and you can restore it at any time.`,
                confirmLabel: 'Archive plan',
                destructive: true,
            };
        case 'restore':
            return {
                title: `Restore ${plan.name}?`,
                description: `${plan.name} comes back with the settings it had when it was archived.`,
                confirmLabel: 'Restore plan',
                destructive: false,
            };
        case 'duplicate':
            return {
                title: `Duplicate ${plan.name}?`,
                description: 'We will make an inactive, hidden copy with the same prices, trial and features, and open it for editing.',
                confirmLabel: 'Duplicate plan',
                destructive: false,
            };
    }
}

function PlanConfirm({
    kind,
    plan,
    open,
    onOpenChange,
    trigger,
}: {
    kind: Kind;
    plan: PlanRef;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    trigger?: ReactNode;
}) {
    const copy = dialogCopy(kind, plan);

    return <ConfirmDialog {...copy} open={open} onOpenChange={onOpenChange} trigger={trigger} onConfirm={() => send(kind, plan)} />;
}

/** Header buttons on the plan detail page. */
export function PlanHeaderActions({ plan }: { plan: PlanRef }) {
    const archived = plan.status === 'archived';

    return (
        <>
            <PlanConfirm
                kind="duplicate"
                plan={plan}
                trigger={
                    <Button variant="outline">
                        <Copy />
                        Duplicate
                    </Button>
                }
            />
            {archived ? (
                <PlanConfirm
                    kind="restore"
                    plan={plan}
                    trigger={
                        <Button>
                            <ArchiveRestore />
                            Restore plan
                        </Button>
                    }
                />
            ) : (
                <PlanConfirm
                    kind="archive"
                    plan={plan}
                    trigger={
                        <Button variant="outline" className="text-destructive hover:text-destructive">
                            <Archive />
                            Archive
                        </Button>
                    }
                />
            )}
        </>
    );
}

/** The "…" menu on each plan row. */
export function PlanRowMenu({ plan }: { plan: PlanRef }) {
    const [dialog, setDialog] = useState<Kind | null>(null);
    const archived = plan.status === 'archived';

    return (
        // Keep clicks and key presses inside the menu from opening the row.
        <div onClick={(event) => event.stopPropagation()} onKeyDown={(event) => event.stopPropagation()} className="flex justify-end">
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${plan.name}`}>
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem onSelect={() => router.visit(route('admin.plans.show', plan.id))}>
                        <Eye />
                        View
                    </DropdownMenuItem>
                    {!archived && (
                        <DropdownMenuItem onSelect={() => router.visit(route('admin.plans.edit', plan.id))}>
                            <Pencil />
                            Edit
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setDialog('duplicate')}>
                        <Copy />
                        Duplicate
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {archived ? (
                        <DropdownMenuItem onSelect={() => setDialog('restore')}>
                            <ArchiveRestore />
                            Restore
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            onSelect={() => setDialog('archive')}
                            className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                        >
                            <Archive />
                            Archive
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {dialog && <PlanConfirm kind={dialog} plan={plan} open onOpenChange={(open) => !open && setDialog(null)} />}
        </div>
    );
}
