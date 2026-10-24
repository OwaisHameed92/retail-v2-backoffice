import { cn } from '@/lib/utils';
import { useState } from 'react';
import { departmentIcon } from './department-icon';

/**
 * A product's photo (its online photo, else the master catalogue's picture of its barcode: StorePhotos, OS-8) on a
 * square, with a shimmer until it loads and a fade in (no layout shift: the box keeps its size). Without a photo, or
 * when it fails to load: a clean branded placeholder, the department's small icon on a pale tint of the store's colour
 * (never a picture of something else; OS-11: calmer, smaller icon, no pattern).
 */
export function ProductPhoto({
    name,
    url,
    className,
    rounded = 'rounded-[20px]',
    alt,
    size = 'md',
    department,
    priority = false,
    sizes = '(min-width: 1280px) 300px, (min-width: 768px) 33vw, 50vw',
}: {
    name: string;
    url: string | null;
    className?: string;
    rounded?: string;
    /** Defaults to the name; pass '' when the name is next to the picture anyway. */
    alt?: string;
    size?: 'sm' | 'md' | 'lg';
    /** The department's name, for the placeholder's icon. */
    department?: string | null;
    /** Above the fold: load at once, high priority. */
    priority?: boolean;
    sizes?: string;
}) {
    const [loaded, setLoaded] = useState(false);
    const [failed, setFailed] = useState(false);

    if (url && !failed) {
        return (
            <div className={cn('relative block aspect-square w-full overflow-hidden bg-white', !loaded && 'store-skeleton', rounded, className)}>
                <img
                    src={url}
                    alt={alt ?? name}
                    loading={priority ? 'eager' : 'lazy'}
                    fetchPriority={priority ? 'high' : 'auto'}
                    decoding="async"
                    sizes={sizes}
                    onLoad={() => setLoaded(true)}
                    onError={() => setFailed(true)}
                    className={cn(
                        'absolute inset-0 size-full object-contain p-[6%] transition-opacity duration-300',
                        loaded ? 'opacity-100' : 'opacity-0',
                    )}
                />
            </div>
        );
    }

    const Icon = departmentIcon(department ?? name);

    return (
        <div
            role={alt === '' ? undefined : 'img'}
            aria-label={alt === '' ? undefined : (alt ?? name)}
            aria-hidden={alt === '' ? true : undefined}
            className={cn(
                'relative flex aspect-square w-full items-center justify-center overflow-hidden bg-[color-mix(in_srgb,var(--color-brand-soft)_45%,white)]',
                rounded,
                className,
            )}
        >
            <span
                className={cn(
                    'text-brand-text/70 relative flex items-center justify-center rounded-full bg-white',
                    size === 'sm' && 'size-8',
                    size === 'md' && 'size-11 sm:size-12',
                    size === 'lg' && 'size-16 sm:size-20',
                )}
            >
                <Icon
                    aria-hidden="true"
                    strokeWidth={1.5}
                    className={cn(size === 'sm' && 'size-4', size === 'md' && 'size-5 sm:size-6', size === 'lg' && 'size-8 sm:size-10')}
                />
            </span>
        </div>
    );
}
