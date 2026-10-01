import { cn } from '@/lib/utils';

/**
 * The full Switch & Save logo (mark, wordmark and "Smart Solutions for Smart Businesses"), 1024×205. Swaps to the
 * white-text version in dark mode. Size it with ONE dimension (e.g. `h-10`, or `w-[196px] h-auto`); it is always
 * object-contain, so it is never cropped or stretched.
 */
export default function BrandLogo({ className, alt = 'Switch & Save' }: { className?: string; alt?: string }) {
    return (
        <>
            <img
                src="/images/brand/switch-save-logo.png"
                alt={alt}
                width={1024}
                height={205}
                decoding="async"
                className={cn('w-auto max-w-full object-contain dark:hidden', className)}
            />
            <img
                src="/images/brand/switch-save-logo-dark.png"
                alt={alt}
                width={1024}
                height={205}
                decoding="async"
                className={cn('hidden w-auto max-w-full object-contain dark:block', className)}
            />
        </>
    );
}
