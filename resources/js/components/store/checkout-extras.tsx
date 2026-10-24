import { cn } from '@/lib/utils';

const NOTE_MAX = 500;

/** Audit OSA-18: a note to the shop and whether a similar item may replace one that has run out. */
export function CheckoutExtras({
    note,
    substitutions,
    onNote,
    onSubstitutions,
    error,
}: {
    note: string;
    substitutions: boolean;
    onNote: (value: string) => void;
    onSubstitutions: (value: boolean) => void;
    error?: string;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1.5">
                <label htmlFor="customer-note" className="text-[13px] font-semibold">
                    Note for the shop (optional)
                </label>
                <textarea
                    id="customer-note"
                    rows={3}
                    maxLength={NOTE_MAX}
                    value={note}
                    placeholder="For example: thinly sliced, no onions"
                    aria-invalid={error ? true : undefined}
                    aria-describedby="customer-note-count"
                    onChange={(e) => onNote(e.target.value)}
                    className={cn(
                        'bg-paper text-ink focus:ring-ink rounded-[14px] border-0 px-4 py-3 text-base outline-none focus:ring-2',
                        error && 'ring-sale ring-2',
                    )}
                />
                <p id="customer-note-count" className={cn('text-xs', error ? 'text-sale font-semibold' : 'text-muted')}>
                    {error ?? `${note.length} of ${NOTE_MAX} characters`}
                </p>
            </div>
            <label className="bg-paper flex cursor-pointer items-start gap-3 rounded-xl px-5 py-4">
                <input
                    type="checkbox"
                    checked={substitutions}
                    onChange={(e) => onSubstitutions(e.target.checked)}
                    className="accent-ink mt-1 size-5 shrink-0"
                />
                <span className="flex flex-col gap-0.5">
                    <span className="font-bold">Allow substitutions</span>
                    <span className="text-body text-sm">
                        If something has run out, the shop may pick a similar item at the same or a lower price.
                    </span>
                </span>
            </label>
        </div>
    );
}

/**
 * Honeypot: only a bot fills it in (the server refuses the order then). People never meet it: visually hidden,
 * aria-hidden and inert (out of the accessibility tree and the tab order), tabindex -1 for older browsers, no autofill.
 */
export function Honeypot({ name, value, onChange }: { name: string; value: string; onChange: (value: string) => void }) {
    return (
        <div aria-hidden="true" inert className="pointer-events-none fixed top-0 -left-[10000px] h-px w-px overflow-hidden opacity-0">
            <label htmlFor="checkout-website">Website</label>
            <input
                id="checkout-website"
                name={name}
                type="text"
                tabIndex={-1}
                autoComplete="off"
                data-1p-ignore
                data-lpignore="true"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}
