import { cn } from '@/lib/utils';
import { useState } from 'react';
import { departmentIcon } from './department-icon';

/**
 * A product's photo (its online photo, else the master catalogue's picture of its barcode: StorePhotos, OS-8) on a
 * square, with a shimmer until it loads and a fade in (no layout shift: the box keeps its size). Without a photo, or
 * when it fails to load: a clean branded placeholder, the department's icon on a soft tint of the store's colour
 * (never a picture of something else).
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
            className={cn('bg-brand-soft relative flex aspect-square w-full items-center justify-center overflow-hidden', rounded, className)}
        >
            <span
                aria-hidden="true"
                className="absolute inset-0 opacity-60"
                style={{ backgroundImage: 'radial-gradient(var(--color-brand-tint) 1.2px, transparent 1.2px)', backgroundSize: '14px 14px' }}
            />
            <span
                className={cn(
                    'text-brand-text relative flex items-center justify-center rounded-full bg-white/85 shadow-[0_1px_0_rgba(0,0,0,0.04)]',
                    size === 'sm' && 'size-10',
                    size === 'md' && 'size-14 sm:size-16',
                    size === 'lg' && 'size-24 sm:size-28',
                )}
            >
                <Icon
                    aria-hidden="true"
                    strokeWidth={1.6}
                    className={cn(size === 'sm' && 'size-5', size === 'md' && 'size-7 sm:size-8', size === 'lg' && 'size-12 sm:size-14')}
                />
            </span>
        </div>
    );
}
