import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Zap, type LucideIcon } from 'lucide-react';

export interface QuickAction {
    label: string;
    icon: LucideIcon;
    href?: string;
    onClick?: () => void;
    /** The first, filled action (e.g. "New tenant"). */
    primary?: boolean;
}

/** "Quick actions" card: a filled primary action, then outlined icon buttons. Hide actions the user cannot do. */
export function QuickActions({ actions, title = 'Quick actions', className }: { actions: QuickAction[]; title?: string; className?: string }) {
    if (actions.length === 0) {
        return null;
    }

    return (
        <Card className={cn('flex flex-col gap-3 p-5', className)}>
            <h2 className="text-foreground flex items-center gap-2 text-base font-semibold tracking-tight">
                <Zap className="text-primary size-4" aria-hidden />
                {title}
            </h2>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {actions.map((action) => {
                    const classes = cn(
                        'focus-visible:ring-ring/35 flex min-h-12 items-center justify-center gap-2 rounded-lg px-3 py-2 text-center text-xs font-medium transition-colors outline-none focus-visible:ring-[3px] [&_svg]:size-4 [&_svg]:shrink-0',
                        action.primary
                            ? 'bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs'
                            : 'border-input bg-card text-foreground hover:border-border-strong hover:bg-muted/60 border',
                    );
                    const content = (
                        <>
                            <action.icon aria-hidden />
                            <span className="leading-tight">{action.label}</span>
                        </>
                    );

                    return action.href ? (
                        <Link key={action.label} href={action.href} prefetch className={classes}>
                            {content}
                        </Link>
                    ) : (
                        <button key={action.label} type="button" onClick={action.onClick} className={classes}>
                            {content}
                        </button>
                    );
                })}
            </div>
        </Card>
    );
}

export default QuickActions;
