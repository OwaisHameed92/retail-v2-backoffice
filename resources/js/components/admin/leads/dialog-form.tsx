import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface DialogFormProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    submitLabel: string;
    processing: boolean;
    onSubmit: FormEventHandler;
    destructive?: boolean;
    disabled?: boolean;
    /** Extra buttons left of Cancel (e.g. "Clear follow-up"). */
    extraActions?: ReactNode;
    className?: string;
    children: ReactNode;
}

/** A small form in a dialog: title, fields, Cancel + primary action with a spinner. Cannot close mid-submit. */
export function DialogForm({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    processing,
    onSubmit,
    destructive = false,
    disabled = false,
    extraActions,
    className,
    children,
}: DialogFormProps) {
    return (
        <Dialog open={open} onOpenChange={(next) => !processing && onOpenChange(next)}>
            <DialogContent className={cn('sm:max-w-lg', className)}>
                <form onSubmit={onSubmit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    {children}
                    <DialogFooter className="gap-2 sm:justify-between">
                        <div className="flex">{extraActions}</div>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                                Cancel
                            </Button>
                            <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={processing || disabled}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                {submitLabel}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
