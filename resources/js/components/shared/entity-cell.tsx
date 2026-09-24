import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/** Deterministic tints for initials avatars, from the chart palette (never red: it reads as an error). */
const tints = [
    'bg-chart-1/12 text-chart-1',
    'bg-chart-2/14 text-success-foreground',
    'bg-chart-3/14 text-chart-3',
    'bg-chart-5/14 text-chart-5',
    'bg-muted text-foreground/70',
] as const;

export function initialsOf(name: string): string {
    const words = name
        .replace(/[^\p{L}\p{N}\s&]/gu, ' ')
        .split(/\s+/)
        .filter((word) => word && word !== '&');
    if (words.length === 0) {
        return '?';
    }
    const first = words[0].charAt(0);
    const second = words.length > 1 ? words[words.length - 1].charAt(0) : words[0].charAt(1);

    return (first + (second ?? '')).toUpperCase();
}

function tintFor(seed: string): string {
    let hash = 0;
    for (let index = 0; index < seed.length; index++) {
        hash = (hash * 31 + seed.charCodeAt(index)) >>> 0;
    }

    return tints[hash % tints.length];
}

interface InitialsAvatarProps {
    name: string;
    /** Square-ish tile for businesses, round for people. */
    shape?: 'circle' | 'square';
    size?: 'sm' | 'md' | 'lg';
    /** Show an icon instead of initials (e.g. KeyRound for licences). */
    icon?: LucideIcon;
    className?: string;
}

const sizes = { sm: 'size-7 text-[11px]', md: 'size-8 text-xs', lg: 'size-12 text-base' } as const;

/** Initials (or icon) on a tint picked from the name, so the same business always gets the same colour. */
export function InitialsAvatar({ name, shape = 'circle', size = 'md', icon: Icon, className }: InitialsAvatarProps) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex shrink-0 items-center justify-center font-semibold tracking-tight select-none',
                shape === 'circle' ? 'rounded-full' : size === 'lg' ? 'rounded-xl' : 'rounded-lg',
                sizes[size],
                tintFor(name),
                className,
            )}
        >
            {Icon ? <Icon className={size === 'lg' ? 'size-5' : 'size-4'} /> : initialsOf(name)}
        </span>
    );
}

interface EntityCellProps {
    name: string;
    /** Second line: legal name, email, code… */
    subline?: ReactNode;
    /** Makes the name a link (stops row-click propagation). */
    href?: string;
    shape?: 'circle' | 'square';
    icon?: LucideIcon;
    /** Replace the initials avatar entirely (e.g. a logo), or `false` to hide it. */
    avatar?: ReactNode | false;
    /** Render the subline in monospace (codes, keys). */
    monoSubline?: boolean;
    /** Badge after the name, e.g. "(you)" or a "Main till" chip. */
    suffix?: ReactNode;
    className?: string;
}

/**
 * First column of most tables: avatar + name + muted subline. Businesses use `shape="square"`, people the
 * default circle.
 */
export function EntityCell({ name, subline, href, shape = 'circle', icon, avatar, monoSubline = false, suffix, className }: EntityCellProps) {
    const title = href ? (
        <Link href={href} onClick={(event) => event.stopPropagation()} className="hover:text-primary truncate font-medium transition-colors">
            {name}
        </Link>
    ) : (
        <span className="truncate font-medium">{name}</span>
    );

    return (
        <div className={cn('flex min-w-0 items-center gap-3', className)}>
            {avatar === undefined ? <InitialsAvatar name={name} shape={shape} icon={icon} /> : avatar}
            <div className="min-w-0 leading-tight">
                <div className="text-foreground flex min-w-0 items-center gap-2">
                    {title}
                    {suffix}
                </div>
                {subline && (
                    <div className={cn('text-muted-foreground mt-0.5 truncate text-xs', monoSubline && 'font-mono text-[11px]')}>{subline}</div>
                )}
            </div>
        </div>
    );
}

export default EntityCell;
