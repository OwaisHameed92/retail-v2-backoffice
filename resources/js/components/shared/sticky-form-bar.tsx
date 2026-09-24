import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface StickyFormBarProps {
    /** Buttons, secondary first: <Button variant="outline">Cancel</Button><Button type="submit">Save changes</Button>. */
    children: ReactNode;
    /** Left side note, e.g. "Unsaved changes" or "Owner gets a welcome email". */
    message?: ReactNode;
    className?: string;
}

/**
 * Action bar for long forms. Sticks to the bottom of the viewport while the form scrolls, then settles under
 * the last section. Primary action is right-most. On phones the buttons fill the width.
 */
export function StickyFormBar({ children, message, className }: StickyFormBarProps) {
    return (
        <div
            className={cn(
                'bg-card/90 supports-[backdrop-filter]:bg-card/75 shadow-raised sticky bottom-3 z-20 flex flex-col gap-3 rounded-xl border px-4 py-3 backdrop-blur-md sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div className="text-muted-foreground min-w-0 text-sm">{message}</div>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center [&>*]:w-full sm:[&>*]:w-auto">{children}</div>
        </div>
    );
}

export default StickyFormBar;
