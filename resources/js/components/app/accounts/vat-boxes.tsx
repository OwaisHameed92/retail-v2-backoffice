import { money } from '@/components/shared/trading/format';
import { cn } from '@/lib/utils';
import { type VatData } from './types';

/** Boxes 1–9 of the VAT return, box 5 highlighted with "to pay" or "to reclaim". Used on screen and on paper. */
export function VatBoxes({ boxes, position, className }: Pick<VatData, 'boxes' | 'position'> & { className?: string }) {
    return (
        <ol className={cn('divide-y rounded-xl border', className)}>
            {boxes.map((b) => {
                const whole = b.box >= 6;
                const highlight = b.box === 5;

                return (
                    <li key={b.box} className={cn('flex items-center gap-4 px-4 py-3 break-inside-avoid sm:px-5', highlight && 'bg-primary/5')}>
                        <span
                            className={cn(
                                'grid size-8 shrink-0 place-items-center rounded-md border text-sm font-semibold tabular-nums',
                                highlight && 'border-primary text-primary',
                            )}
                        >
                            {b.box}
                        </span>
                        <span className="min-w-0 flex-1 text-sm">
                            {b.label}
                            {highlight && (
                                <span className="text-muted-foreground block text-xs">
                                    {position === 'reclaim' ? 'You reclaim this from HMRC' : 'You pay this to HMRC'}
                                </span>
                            )}
                            {whole && b.box === 6 && <span className="text-muted-foreground block text-xs">Whole pounds</span>}
                        </span>
                        <span className={cn('text-right font-semibold tabular-nums', highlight && 'text-lg')}>
                            {whole ? money(b.amount).replace(/\.00$/, '') : money(b.amount)}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
