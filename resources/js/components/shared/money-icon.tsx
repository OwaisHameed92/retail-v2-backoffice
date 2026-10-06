import { country } from '@/lib/country';
import { Banknote, PoundSterling, type LucideIcon, type LucideProps } from 'lucide-react';
import { forwardRef } from 'react';

/**
 * The money icon of the country profile (phase P2): the pound sign on GB, as before; a banknote for any other currency.
 * A drop-in for a lucide icon (`icon={MoneyIcon}`); the profile is read when it renders.
 */
export const MoneyIcon = forwardRef<SVGSVGElement, LucideProps>(function MoneyIcon(props, ref) {
    const Icon = country().currency === 'GBP' ? PoundSterling : Banknote;

    return <Icon ref={ref} {...props} />;
}) as LucideIcon;
