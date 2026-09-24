import { cn } from '@/lib/utils';

/** Full Switch & Save wordmark; swaps to the white-text version in dark mode. Size it with a height class (e.g. h-10). */
export default function BrandLogo({ className }: { className?: string }) {
    return (
        <>
            <img src="/images/brand/switch-save-logo.png" alt="Switch & Save" className={cn('w-auto object-contain dark:hidden', className)} />
            <img src="/images/brand/switch-save-logo-dark.png" alt="Switch & Save" className={cn('hidden w-auto object-contain dark:block', className)} />
        </>
    );
}
