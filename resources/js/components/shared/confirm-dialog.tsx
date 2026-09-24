import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';

interface ConfirmDialogProps {
    title: string;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Red confirm button for destructive actions (suspend, revoke, delete). */
    destructive?: boolean;
    /** May return a promise; the dialog stays open and shows a spinner until it settles. */
    onConfirm: () => void | Promise<unknown>;
    /** Element that opens the dialog. Omit when controlling `open` yourself. */
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /** Force the busy state, e.g. from Inertia's `processing`. */
    processing?: boolean;
    children?: ReactNode;
}

export function ConfirmDialog({
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    destructive = false,
    onConfirm,
    trigger,
    open,
    onOpenChange,
    processing = false,
    children,
}: ConfirmDialogProps) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const isOpen = open ?? internalOpen;
    const isBusy = busy || processing;

    const setOpen = (value: boolean) => {
        if (isBusy && !value) {
            return;
        }
        setInternalOpen(value);
        onOpenChange?.(value);
    };

    const handleConfirm = async (event: MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        setBusy(true);
        try {
            await onConfirm();
            setInternalOpen(false);
            onOpenChange?.(false);
        } finally {
            setBusy(false);
        }
    };

    return (
        <AlertDialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    {description && <AlertDialogDescription>{description}</AlertDialogDescription>}
                </AlertDialogHeader>
                {children}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isBusy}>{cancelLabel}</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleConfirm}
                        disabled={isBusy}
                        className={cn(destructive && buttonVariants({ variant: 'destructive' }))}
                    >
                        {isBusy && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default ConfirmDialog;
