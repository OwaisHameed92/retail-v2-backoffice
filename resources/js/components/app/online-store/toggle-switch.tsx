import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-react';

interface ToggleSwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    /** Accessible name when there is no visible label tied to it with `id`. */
    label?: string;
    id?: string;
    disabled?: boolean;
    busy?: boolean;
    className?: string;
}

/** An on/off switch (role="switch", Space/Enter toggle) in the brand primary colour. */
export function ToggleSwitch({ checked, onCheckedChange, label, id, disabled = false, busy = false, className }: ToggleSwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            id={id}
            aria-checked={checked}
            aria-label={label}
            aria-busy={busy || undefined}
            disabled={disabled || busy}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'focus-visible:ring-ring/40 relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-colors duration-150 outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary' : 'bg-border-strong',
                className,
            )}
        >
            <span
                className={cn(
                    'bg-card pointer-events-none flex size-5 items-center justify-center rounded-full shadow-sm transition-transform duration-150',
                    checked ? 'translate-x-[1.3rem]' : 'translate-x-0.5',
                )}
            >
                {busy && <LoaderCircle className="text-muted-foreground size-3 animate-spin" />}
            </span>
        </button>
    );
}
