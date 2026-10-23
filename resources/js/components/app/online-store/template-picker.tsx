import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import { type TemplateKey, type TemplateOption } from './types';

interface TemplatePickerProps {
    templates: TemplateOption[];
    value: TemplateKey;
    onChange: (value: TemplateKey) => void;
    disabled?: boolean;
}

/** The three store looks as photo cards (a radio group: arrow keys move between them). */
export function TemplatePicker({ templates, value, onChange, disabled }: TemplatePickerProps) {
    return (
        <div role="radiogroup" aria-label="Store template" className="grid gap-4 sm:grid-cols-3">
            {templates.map((t) => {
                const on = t.value === value;

                return (
                    <button
                        key={t.value}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        disabled={disabled}
                        tabIndex={on ? 0 : -1}
                        onClick={() => onChange(t.value)}
                        onKeyDown={(e) => {
                            const index = templates.findIndex((x) => x.value === value);
                            const step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
                            if (step !== 0) {
                                e.preventDefault();
                                const next = templates[(index + step + templates.length) % templates.length];
                                onChange(next.value);
                                (
                                    e.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>('[role="radio"]')[templates.indexOf(next)] ??
                                    null
                                )?.focus();
                            }
                        }}
                        className={cn(
                            'group bg-card focus-visible:ring-ring/40 relative overflow-hidden rounded-xl border text-left transition-all duration-150 outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-60',
                            on ? 'border-primary ring-primary ring-1' : 'hover:border-border-strong',
                        )}
                    >
                        <div className="bg-muted aspect-[4/3] overflow-hidden">
                            <img src={t.photo} alt="" className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" />
                        </div>
                        <div className="flex items-start justify-between gap-2 p-3">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">{t.label}</p>
                                <p className="text-muted-foreground text-xs leading-5">{t.for}</p>
                            </div>
                            <span
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                    on ? 'bg-primary-soft text-primary' : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {on && <Check className="size-3" />}
                                {on ? 'In use' : 'Use this'}
                            </span>
                        </div>
                    </button>
                );
            })}
        </div>
    );
}
