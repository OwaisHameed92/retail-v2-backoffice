import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { type BannerSlide } from './types';

const INTERVAL_MS = 6500;

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * The home page's banners (OS-8): an accessible carousel (WAI-ARIA carousel pattern). Every slide sits in the same
 * grid cell, so the box is as tall as the tallest slide and nothing moves when it changes (no layout shift); hidden
 * slides are `inert`. Auto-advances every 6.5 s, pausing while the pointer is over it or focus is inside, with a
 * pause button (WCAG 2.2.2); never with reduced motion. Arrows, dots and swipe. One slide: no controls.
 * Each template draws its own slide (`render`) and places the controls (`controls`).
 */
export function BannerCarousel({
    slides,
    label,
    render,
    className,
    controlsClassName,
    tone = 'light',
}: {
    slides: BannerSlide[];
    label: string;
    render: (slide: BannerSlide, index: number, active: boolean) => ReactNode;
    className?: string;
    controlsClassName?: string;
    tone?: 'light' | 'dark';
}) {
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);
    const [reduced, setReduced] = useState(prefersReducedMotion);
    const touch = useRef<number | null>(null);
    const count = slides.length;
    const many = count > 1;
    const go = useCallback((next: number) => setIndex(((next % count) + count) % count), [count]);
    const playing = many && !paused && !hovered && !focused && !reduced;

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const change = () => setReduced(query.matches);
        query.addEventListener('change', change);

        return () => query.removeEventListener('change', change);
    }, []);

    useEffect(() => {
        if (!playing) {
            return;
        }

        const timer = window.setTimeout(() => go(index + 1), INTERVAL_MS);

        return () => window.clearTimeout(timer);
    }, [playing, index, go]);

    const button = cn(
        'flex size-9 items-center justify-center rounded-full backdrop-blur transition',
        tone === 'dark' ? 'bg-black/35 text-white hover:bg-black/55' : 'text-ink hover:bg-brand hover:text-on-brand bg-white/90 shadow-sm',
    );

    return (
        <section
            aria-roledescription="carousel"
            aria-label={label}
            className={cn('relative', className)}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setFocused(true)}
            onBlur={(e) => !e.currentTarget.contains(e.relatedTarget as Node | null) && setFocused(false)}
            onTouchStart={(e) => (touch.current = e.touches[0]?.clientX ?? null)}
            onTouchEnd={(e) => {
                const start = touch.current;
                const end = e.changedTouches[0]?.clientX;
                touch.current = null;

                if (many && start !== null && end !== undefined && Math.abs(end - start) > 45) {
                    go(index + (end < start ? 1 : -1));
                }
            }}
        >
            <div className="grid" aria-live={playing ? 'off' : 'polite'}>
                {slides.map((slide, i) => (
                    <div
                        key={slide.id}
                        role={many ? 'group' : undefined}
                        aria-roledescription={many ? 'slide' : undefined}
                        aria-label={many ? `${i + 1} of ${count}` : undefined}
                        aria-hidden={i !== index}
                        inert={i !== index}
                        className={cn(
                            'transition-opacity duration-700 ease-out [grid-area:1/1] motion-reduce:transition-none',
                            i === index ? 'z-[1] opacity-100' : 'pointer-events-none opacity-0',
                        )}
                    >
                        {render(slide, i, i === index)}
                    </div>
                ))}
            </div>

            {many && (
                <div className={cn('absolute z-10 flex items-center gap-1.5', controlsClassName ?? 'right-4 bottom-4')}>
                    <button type="button" className={button} onClick={() => go(index - 1)} aria-label="Previous banner">
                        <ChevronLeft aria-hidden="true" className="size-4" />
                    </button>
                    <div
                        className={cn(
                            'flex items-center rounded-full px-1.5 py-1 backdrop-blur',
                            tone === 'dark' ? 'bg-black/35' : 'bg-white/90 shadow-sm',
                        )}
                    >
                        {slides.map((slide, i) => (
                            <button
                                key={slide.id}
                                type="button"
                                onClick={() => go(i)}
                                aria-label={`Show banner ${i + 1} of ${count}`}
                                aria-current={i === index ? 'true' : undefined}
                                className="flex size-5 items-center justify-center"
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'block h-1.5 rounded-full transition-all',
                                        i === index
                                            ? cn('w-4', tone === 'dark' ? 'bg-white' : 'bg-brand')
                                            : cn('w-1.5', tone === 'dark' ? 'bg-white/55' : 'bg-ink/30'),
                                    )}
                                />
                            </button>
                        ))}
                    </div>
                    <button type="button" className={button} onClick={() => go(index + 1)} aria-label="Next banner">
                        <ChevronRight aria-hidden="true" className="size-4" />
                    </button>
                    {!reduced && (
                        <button
                            type="button"
                            className={button}
                            onClick={() => setPaused((p) => !p)}
                            aria-label={paused ? 'Play the banners' : 'Pause the banners'}
                            aria-pressed={paused}
                        >
                            {paused ? <Play aria-hidden="true" className="size-3.5" /> : <Pause aria-hidden="true" className="size-3.5" />}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
