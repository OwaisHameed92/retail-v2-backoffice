import { cn } from '@/lib/utils';

const LINKS = [
    { page: 'terms', label: 'Terms' },
    { page: 'privacy', label: 'Privacy' },
    { page: 'dpa', label: 'Data processing' },
    { page: 'subprocessors', label: 'Sub-processors' },
];

/** Links to the public legal pages (module 7.7), for footers and the sign-in pages. */
export function LegalLinks({ className }: { className?: string }) {
    return (
        <nav aria-label="Legal" className={cn('text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs', className)}>
            {LINKS.map((link) => (
                <a key={link.page} href={route('legal', link.page)} className="hover:text-foreground underline-offset-4 hover:underline">
                    {link.label}
                </a>
            ))}
        </nav>
    );
}
