import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff, LoaderCircle, Lock, type LucideIcon } from 'lucide-react';
import { forwardRef, useState, type ComponentProps, type ReactNode } from 'react';

type InputProps = ComponentProps<typeof Input>;

/** Sign-in sized text field (44px) with a leading icon. Pass `aria-describedby` for its error/help line. */
export const IconInput = forwardRef<HTMLInputElement, InputProps & { icon: LucideIcon; trailing?: ReactNode }>(
    ({ icon: Icon, trailing, className, ...props }, ref) => (
        <div className="group/field relative">
            <Icon
                className="text-muted-foreground group-focus-within/field:text-primary pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 transition-colors"
                aria-hidden
            />
            <Input ref={ref} className={cn('h-11 pl-10', trailing ? 'pr-11' : undefined, className)} {...props} />
            {trailing && <div className="absolute inset-y-0 right-1 flex items-center">{trailing}</div>}
        </div>
    ),
);
IconInput.displayName = 'IconInput';

/** Password field with a lock icon and a show/hide toggle that keeps focus order natural (field, then toggle). */
export const PasswordInput = forwardRef<HTMLInputElement, Omit<InputProps, 'type'> & { icon?: LucideIcon }>(({ icon = Lock, ...props }, ref) => {
    const [visible, setVisible] = useState(false);
    const ToggleIcon = visible ? EyeOff : Eye;

    return (
        <IconInput
            ref={ref}
            icon={icon}
            type={visible ? 'text' : 'password'}
            {...props}
            trailing={
                <button
                    type="button"
                    onClick={() => setVisible((value) => !value)}
                    aria-label={visible ? 'Hide password' : 'Show password'}
                    aria-pressed={visible}
                    aria-controls={props.id}
                    className="text-muted-foreground hover:text-foreground hover:bg-muted focus-visible:ring-ring/40 flex size-9 items-center justify-center rounded-md transition-colors outline-none focus-visible:ring-[3px]"
                >
                    <ToggleIcon className="size-4" aria-hidden />
                </button>
            }
        />
    );
});
PasswordInput.displayName = 'PasswordInput';

/** Full-width primary button for auth forms: spinner and `aria-busy` while the request runs, disabled meanwhile. */
export function AuthSubmit({
    processing,
    disabled,
    children,
    icon: Icon,
    className,
    ...props
}: ComponentProps<typeof Button> & { processing: boolean; icon?: LucideIcon }) {
    return (
        <Button
            type="submit"
            size="lg"
            className={cn('h-11 w-full text-[15px] font-semibold shadow-sm', className)}
            disabled={processing || disabled}
            aria-busy={processing || undefined}
            {...props}
        >
            {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : Icon && <Icon aria-hidden />}
            {children}
        </Button>
    );
}

/** Hairline divider with optional centred text ("or"). */
export function AuthDivider({ children, className }: { children?: ReactNode; className?: string }) {
    if (!children) {
        return <div role="separator" className={cn('bg-border h-px', className)} />;
    }

    return (
        <div className={cn('flex items-center gap-3', className)}>
            <span className="bg-border h-px flex-1" />
            <span className="text-muted-foreground text-xs font-medium">{children}</span>
            <span className="bg-border h-px flex-1" />
        </div>
    );
}

/** Success / info message at the top of an auth form. */
export function AuthNotice({ children }: { children: ReactNode }) {
    return (
        <div role="status" className="bg-success-soft text-success-foreground border-success/25 mb-6 rounded-xl border px-4 py-3 text-sm leading-6">
            {children}
        </div>
    );
}
