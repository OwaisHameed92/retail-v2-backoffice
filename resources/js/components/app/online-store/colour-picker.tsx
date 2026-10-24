import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface ColourPickerProps {
    colours: { name: string; hex: string }[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    disabled?: boolean;
}

const HEX = /^#[0-9A-Fa-f]{6}$/;

/** WCAG contrast of two #RRGGBB colours (the server's BrandPalette does the same sums for the live store). */
export function contrast(a: string, b: string): number {
    const luminance = (hex: string) => {
        const [r, g, bl] = [1, 3, 5]
            .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
            .map((v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)));

        return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
    };
    const [x, y] = [luminance(a), luminance(b)];

    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/** Audit OSA-16: what the store does with a colour that is hard to read on white, or null when it reads well. */
function readability(hex: string): string | null {
    if (!HEX.test(hex)) {
        return null;
    }

    const onWhite = contrast(hex, '#FFFFFF');

    if (onWhite >= 4.5) {
        return null;
    }

    return onWhite < 3
        ? 'This is a light colour: buttons and bars get dark text on it, and links and prices use a darker shade so they stay readable (WCAG AA).'
        : 'Links and prices use a slightly darker shade of this colour so they stay readable on white (WCAG AA).';
}

/** Preset swatches plus a custom colour (picker and #RRGGBB text). An empty value means the template's own colour. */
export function ColourPicker({ colours, value, onChange, error, disabled }: ColourPickerProps) {
    const custom = value !== '' && !colours.some((c) => c.hex.toLowerCase() === value.toLowerCase());

    return (
        <div className="grid gap-3">
            <div role="radiogroup" aria-label="Main colour" className="flex flex-wrap items-center gap-2.5">
                <button
                    type="button"
                    role="radio"
                    aria-checked={value === ''}
                    disabled={disabled}
                    onClick={() => onChange('')}
                    className={cn(
                        'focus-visible:ring-ring/40 h-9 rounded-full border px-3 text-xs font-medium transition-colors outline-none focus-visible:ring-[3px]',
                        value === '' ? 'border-primary bg-primary-soft text-primary' : 'text-muted-foreground hover:border-border-strong',
                    )}
                >
                    Template colour
                </button>
                {colours.map((c) => {
                    const on = c.hex.toLowerCase() === value.toLowerCase();

                    return (
                        <button
                            key={c.hex}
                            type="button"
                            role="radio"
                            aria-checked={on}
                            aria-label={c.name}
                            title={c.name}
                            disabled={disabled}
                            onClick={() => onChange(c.hex)}
                            className={cn(
                                'focus-visible:ring-ring/40 ring-offset-card flex size-9 items-center justify-center rounded-full ring-offset-2 transition-shadow outline-none focus-visible:ring-[3px]',
                                on && 'ring-foreground ring-2',
                            )}
                            style={{ backgroundColor: c.hex }}
                        />
                    );
                })}
            </div>
            <div className="flex items-center gap-2">
                <label
                    className={cn(
                        'relative flex size-9 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border',
                        custom && 'ring-foreground ring-offset-card ring-2 ring-offset-2',
                    )}
                    style={{ backgroundColor: HEX.test(value) ? value : undefined }}
                >
                    <span className="sr-only">Pick a custom colour</span>
                    <input
                        type="color"
                        className="absolute inset-0 size-full cursor-pointer opacity-0"
                        value={HEX.test(value) ? value : '#0b4a34'}
                        disabled={disabled}
                        onChange={(e) => onChange(e.target.value.toUpperCase())}
                    />
                </label>
                <Input
                    id="brand_colour"
                    className="h-9 w-32 font-mono uppercase"
                    placeholder="#1F7A4D"
                    maxLength={7}
                    value={value}
                    disabled={disabled}
                    aria-invalid={!!error}
                    aria-describedby={error ? 'brand_colour-error' : undefined}
                    onChange={(e) => {
                        const next = e.target.value.trim();
                        onChange(next === '' ? '' : next.startsWith('#') ? next : `#${next}`);
                    }}
                />
                <span className="text-muted-foreground text-xs">Custom colour</span>
            </div>
            {!error && readability(value) && (
                <p role="status" className="bg-warning-soft text-warning-foreground rounded-md px-3 py-2 text-[13px] leading-5">
                    {readability(value)}
                </p>
            )}
            {error && (
                <p id="brand_colour-error" className="text-danger-foreground text-[13px] leading-5">
                    {error}
                </p>
            )}
        </div>
    );
}
