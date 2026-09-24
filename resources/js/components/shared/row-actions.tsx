import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import { Fragment } from 'react';

export interface RowAction {
    label: string;
    icon?: LucideIcon;
    /** Navigate with Inertia. */
    href?: string;
    onSelect?: () => void;
    /** Red text; put destructive actions last (a separator is added before the first one). */
    destructive?: boolean;
    disabled?: boolean;
    /** Hide without filtering the array yourself (permissions). */
    hidden?: boolean;
}

interface RowActionsProps {
    actions: RowAction[];
    /** Accessible name, e.g. "Actions for Khan Mini Mart". */
    label: string;
    align?: 'start' | 'end';
    className?: string;
}

/**
 * The "…" menu at the end of a table row or card. Clicks inside never trigger the row's own click. For
 * destructive actions, open a ConfirmDialog from `onSelect` (controlled) rather than acting directly.
 */
export function RowActions({ actions, label, align = 'end', className }: RowActionsProps) {
    const visible = actions.filter((action) => !action.hidden);
    if (visible.length === 0) {
        return null;
    }
    const firstDestructive = visible.findIndex((action) => action.destructive);

    return (
        <div
            className={cn('flex justify-end', className)}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
        >
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="text-muted-foreground data-[state=open]:bg-muted size-8" aria-label={label}>
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align={align} className="min-w-44">
                    {visible.map((action, index) => {
                        const Icon = action.icon;
                        const content = (
                            <>
                                {Icon && (
                                    <Icon className={cn('size-4', action.destructive ? 'text-destructive' : 'text-muted-foreground')} aria-hidden />
                                )}
                                {action.label}
                            </>
                        );

                        return (
                            <Fragment key={action.label}>
                                {index === firstDestructive && index > 0 && <DropdownMenuSeparator />}
                                {action.href && !action.disabled ? (
                                    <DropdownMenuItem asChild className={cn(action.destructive && 'text-destructive focus:text-destructive')}>
                                        <Link href={action.href}>{content}</Link>
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem
                                        disabled={action.disabled}
                                        onSelect={() => action.onSelect?.()}
                                        className={cn(action.destructive && 'text-destructive focus:text-destructive')}
                                    >
                                        {content}
                                    </DropdownMenuItem>
                                )}
                            </Fragment>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

export default RowActions;
