import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { ArrowUp, Check, Copy, Gauge, type LucideIcon, Plug, PowerOff, ShieldAlert, Sparkles, Square } from 'lucide-react';
import { type KeyboardEvent, type Ref, useState } from 'react';

/**
 * Building blocks of an AI assistant panel, shared by the portal assistant (module 6.2) and the admin assistant
 * (module 6.7): usage meter, empty state with example questions, "not available" states, the composer and a copy
 * button. Presentational only; the panel owns the state.
 */

export interface AssistantUsageMeterProps {
    used: number;
    limit: number;
    percent: number;
    resetsOn: string;
}

export function AssistantUsageMeter({ used, limit, percent, resetsOn }: AssistantUsageMeterProps) {
    return (
        <div className="space-y-1" title={`${used.toLocaleString('en-GB')} of ${limit.toLocaleString('en-GB')} tokens`}>
            <div className="text-muted-foreground flex justify-between gap-2 text-xs">
                <span>This month's allowance: {percent}% used</span>
                <span>Resets {resetsOn}</span>
            </div>
            <div
                className="bg-muted h-1.5 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="AI allowance used"
            >
                <div
                    className={cn('h-full rounded-full transition-[width]', percent >= 90 ? 'bg-destructive' : percent >= 75 ? 'bg-warning' : 'bg-primary')}
                    style={{ width: `${Math.min(100, Math.max(2, percent))}%` }}
                />
            </div>
        </div>
    );
}

/** The first screen of a new chat: what the assistant does and (at most) four questions to start with. */
export function AssistantEmptyState({
    examples,
    onPick,
    intro = 'Ask about your business in plain English. Try one of these:',
}: {
    examples: string[];
    onPick: (question: string) => void;
    intro?: string;
}) {
    return (
        <div className="my-auto space-y-5 py-6 text-center">
            <span className="bg-info-soft text-primary mx-auto flex size-12 items-center justify-center rounded-2xl" aria-hidden>
                <Sparkles className="size-5" />
            </span>
            <p className="text-muted-foreground mx-auto max-w-sm text-sm">{intro}</p>
            <div className="grid gap-2 sm:grid-cols-2">
                {examples.slice(0, 4).map((example) => (
                    <button
                        key={example}
                        type="button"
                        onClick={() => onPick(example)}
                        className="border-border bg-card hover:border-primary/40 hover:bg-info-soft focus-visible:ring-ring/30 rounded-xl border px-3.5 py-3 text-left text-sm leading-snug transition-colors focus-visible:ring-[3px] focus-visible:outline-none"
                    >
                        {example}
                    </button>
                ))}
            </div>
        </div>
    );
}

const UNAVAILABLE: Record<string, { icon: LucideIcon; title: string; hint?: string }> = {
    notConfigured: {
        icon: Plug,
        title: 'AI is not configured yet',
        hint: 'Once Switch & Save switches it on, you can ask about sales, stock, cash and staff here.',
    },
    budgetExhausted: {
        icon: Gauge,
        title: "This month's AI allowance is used up",
        hint: 'Your reports and every other page work as normal. Ask Switch & Save if you need a bigger allowance.',
    },
    disabled: { icon: PowerOff, title: 'AI is switched off for now' },
    notInPlan: { icon: ShieldAlert, title: 'Not included in your plan' },
};

/** Why the assistant cannot answer (not configured, monthly limit reached, switched off, not in the plan, on hold). */
export function AssistantUnavailableState({ reason, message }: { reason: string | null; message: string | null }) {
    const state = UNAVAILABLE[reason ?? ''] ?? { icon: ShieldAlert, title: 'The assistant is not available' };
    const Icon = state.icon;

    return (
        <div className="flex flex-1 flex-col items-center justify-center px-6 py-10 text-center" role="status">
            <span
                className={cn(
                    'flex size-12 items-center justify-center rounded-full',
                    reason === 'budgetExhausted' ? 'bg-warning-soft text-warning-foreground' : 'bg-muted text-muted-foreground',
                )}
            >
                <Icon className="size-5" aria-hidden />
            </span>
            <p className="mt-4 text-base font-semibold">{state.title}</p>
            {message && <p className="text-muted-foreground mt-1.5 max-w-xs text-sm">{message}</p>}
            {state.hint && <p className="text-muted-foreground mt-3 max-w-xs text-xs">{state.hint}</p>}
        </div>
    );
}

/** Copies an answer to the clipboard; ticks for two seconds. */
export function AssistantCopyButton({ text, className }: { text: string; className?: string }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn('text-muted-foreground hover:text-foreground h-7 gap-1 px-2 text-xs', className)}
            onClick={() => void copy()}
            aria-label={copied ? 'Copied' : 'Copy answer'}
        >
            {copied ? <Check className="size-3.5" aria-hidden /> : <Copy className="size-3.5" aria-hidden />}
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

/**
 * The question box: Enter sends, Shift+Enter adds a line, Stop while an answer streams. The footer says how to open
 * (⌘K / Ctrl+K) and close (Esc) the panel.
 */
export function AssistantComposer({
    value,
    onChange,
    onSend,
    onStop,
    busy,
    disabled,
    placeholder,
    maxLength = 4000,
    inputRef,
    note = 'Answers can be wrong: check the linked report. Changes only happen when you confirm them.',
}: {
    value: string;
    onChange: (value: string) => void;
    onSend: () => void;
    onStop?: () => void;
    busy: boolean;
    disabled: boolean;
    placeholder: string;
    maxLength?: number;
    inputRef?: Ref<HTMLTextAreaElement>;
    note?: string;
}) {
    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            onSend();
        }
    };
    const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

    return (
        <div className="border-border bg-background border-t px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))] sm:px-5">
            <div className="flex items-end gap-2">
                <Textarea
                    ref={inputRef}
                    value={value}
                    onChange={(e) => onChange(e.target.value.slice(0, maxLength))}
                    onKeyDown={onKeyDown}
                    rows={2}
                    disabled={disabled}
                    placeholder={placeholder}
                    aria-label="Your question"
                    className="max-h-40 min-h-[44px] resize-none text-base sm:text-sm"
                />
                {busy && onStop ? (
                    <Button size="icon" variant="outline" className="size-11 shrink-0 rounded-xl" onClick={onStop} aria-label="Stop answering">
                        <Square className="size-3.5 fill-current" aria-hidden />
                    </Button>
                ) : (
                    <Button
                        size="icon"
                        className="size-11 shrink-0 rounded-xl"
                        onClick={onSend}
                        disabled={disabled || busy || value.trim() === ''}
                        aria-label="Send"
                    >
                        <ArrowUp aria-hidden />
                    </Button>
                )}
            </div>
            <div className="text-muted-foreground mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
                <span>{note}</span>
                <span className="hidden items-center gap-1 sm:inline-flex">
                    <kbd className="bg-muted rounded border px-1 font-sans text-[10px]">{isMac ? '⌘' : 'Ctrl'} K</kbd> open
                    <kbd className="bg-muted ml-1 rounded border px-1 font-sans text-[10px]">Esc</kbd> close
                </span>
            </div>
        </div>
    );
}
