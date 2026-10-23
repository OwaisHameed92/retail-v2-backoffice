import { cn } from '@/lib/utils';
import { initialOf, tintFor } from './format';
import { useTemplate } from './theme';

/**
 * A product's online photo, or a clean placeholder tile: the name's first letter on a soft tint (no stock images,
 * so customers never see a picture of something else).
 */
export function ProductPhoto({
    name,
    url,
    className,
    rounded = 'rounded-[20px]',
    alt,
    size = 'md',
}: {
    name: string;
    url: string | null;
    className?: string;
    rounded?: string;
    /** Defaults to the name; pass '' when the name is next to the picture anyway. */
    alt?: string;
    size?: 'sm' | 'md' | 'lg';
}) {
    const template = useTemplate();

    if (url) {
        return (
            <img
                src={url}
                alt={alt ?? name}
                loading="lazy"
                decoding="async"
                className={cn('bg-tile block aspect-square w-full object-cover', rounded, className)}
            />
        );
    }

    return (
        <div
            role={alt === '' ? undefined : 'img'}
            aria-label={alt === '' ? undefined : (alt ?? name)}
            aria-hidden={alt === '' ? true : undefined}
            className={cn('flex aspect-square w-full items-center justify-center', rounded, className)}
            style={{ backgroundColor: tintFor(name, template) }}
        >
            <span
                className={cn(
                    'font-display text-ink/70 font-extrabold tracking-tight select-none',
                    size === 'sm' && 'text-2xl',
                    size === 'md' && 'text-5xl sm:text-6xl',
                    size === 'lg' && 'text-8xl sm:text-9xl',
                )}
            >
                {initialOf(name)}
            </span>
        </div>
    );
}
