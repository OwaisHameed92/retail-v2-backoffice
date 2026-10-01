import { cn } from '@/lib/utils';

/**
 * The soft wave lines of the Switch & Save wash (top bar, dashboard hero, auth panel). Decorative only: place it
 * inside a `relative overflow-hidden` surface with `bg-chrome-frame` or `bg-brand-wash`. Colour and strength come
 * from the --wave-stroke / --wave-opacity tokens, so dark mode follows.
 */
export function BrandWaves({ className }: { className?: string }) {
    return (
        <svg
            className={cn('brand-waves absolute inset-y-0 right-0 h-full w-[70%]', className)}
            viewBox="0 0 800 120"
            preserveAspectRatio="none"
            fill="none"
            aria-hidden
        >
            <path d="M0 96C120 70 220 40 340 52S560 112 680 86 780 30 800 24" stroke="currentColor" strokeWidth="1.5" />
            <path d="M0 108C140 84 240 58 360 68S580 120 700 98 790 52 800 46" stroke="currentColor" strokeWidth="1.2" />
            <path d="M0 80C110 58 230 22 350 36S570 96 690 70 780 14 800 8" stroke="currentColor" strokeWidth="1" />
            <path d="M120 120C260 92 380 70 500 86S700 118 800 70V120Z" fill="currentColor" fillOpacity="0.35" />
            <path d="M300 120C420 104 520 90 620 98S760 112 800 96V120Z" fill="currentColor" fillOpacity="0.4" />
        </svg>
    );
}

export default BrandWaves;
