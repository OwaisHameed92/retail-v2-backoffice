import { cn } from '@/lib/utils';
import { type ImgHTMLAttributes } from 'react';

/** The round Switch & Save "S" mark. Size it with className (e.g. size-8). */
export default function AppLogoIcon({ className, alt = 'Switch & Save', ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/images/brand/switch-save-icon.png" alt={alt} className={cn('object-contain', className)} {...props} />;
}
