import { Input } from '@/components/ui/input';
import { type ComponentProps } from 'react';

/** A one-time code field: numeric keypad on phones, autofill from SMS/password managers, monospace digits. */
export function CodeInput({ allowRecovery = false, className, ...props }: ComponentProps<typeof Input> & { allowRecovery?: boolean }) {
    return (
        <Input
            type="text"
            inputMode={allowRecovery ? 'text' : 'numeric'}
            autoComplete="one-time-code"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            maxLength={allowRecovery ? 32 : 6}
            className={['h-11 text-center font-mono text-lg tracking-[0.3em] tabular-nums', className].filter(Boolean).join(' ')}
            {...props}
        />
    );
}
