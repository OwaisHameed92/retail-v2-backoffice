import { cn } from '@/lib/utils';
import { Minus, Plus } from 'lucide-react';
import { quantityLabel, stepQuantity } from './format';

/**
 * − quantity + for a product: whole numbers, or 0.25 kg steps for weighed products, between `min` and `max`.
 * 44px+ targets; the value is announced with its unit.
 */
export function QtyStepper({
    value,
    onChange,
    step,
    min,
    max,
    weighed,
    name,
    disabled = false,
    tone = 'light',
    size = 'md',
}: {
    value: string;
    onChange: (value: string) => void;
    step: string;
    min: string;
    max: string;
    weighed: boolean;
    name: string;
    disabled?: boolean;
    tone?: 'light' | 'paper' | 'dark';
    size?: 'md' | 'lg';
}) {
    const atMin = Number(value) <= Number(min);
    const atMax = Number(value) >= Number(max);
    const button = cn(
        'flex shrink-0 items-center justify-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-35',
        size === 'lg' ? 'size-14' : 'size-11',
        tone === 'dark' ? 'text-white hover:bg-white/10' : 'text-ink hover:bg-brand-soft',
    );

    return (
        <div
            role="group"
            aria-label={`Quantity of ${name}`}
            className={cn(
                'inline-flex items-center rounded-full',
                tone === 'light' && 'bg-white',
                tone === 'paper' && 'bg-paper',
                tone === 'dark' && 'bg-transparent text-white',
            )}
        >
            <button
                type="button"
                className={button}
                onClick={() => onChange(stepQuantity(value, step, -1, min, max))}
                disabled={disabled || atMin}
                aria-label={weighed ? `${step} kg less` : 'One less'}
            >
                <Minus aria-hidden="true" className="size-5" strokeWidth={2.4} />
            </button>
            <output aria-live="polite" className={cn('min-w-12 text-center font-bold tabular-nums', size === 'lg' ? 'text-lg' : 'text-base')}>
                {quantityLabel(value, weighed)}
            </output>
            <button
                type="button"
                className={button}
                onClick={() => onChange(stepQuantity(value, step, 1, min, max))}
                disabled={disabled || atMax}
                aria-label={weighed ? `${step} kg more` : 'One more'}
            >
                <Plus aria-hidden="true" className="size-5" strokeWidth={2.4} />
            </button>
        </div>
    );
}
