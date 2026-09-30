import { cellText } from '@/components/app/reports/format';
import { type ReportFigure } from '@/components/app/reports/types';
import { StatCard } from '@/components/shared/stat-card';
import { changeDelta, moneyShort } from '@/components/shared/trading/format';
import { cn } from '@/lib/utils';

/**
 * The report's headline figures, each with its change against the compare window ("vs previous period") when the
 * report compares. A variance figure is red when short.
 */
export function ReportSummary({ figures, versus }: { figures: ReportFigure[]; versus: string }) {
    if (figures.length === 0) {
        return null;
    }

    return (
        <div className={cn('grid gap-4 sm:grid-cols-2', figures.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4')}>
            {figures.map((figure) => {
                const negative = figure.type === 'signedMoney' && Number(figure.value) < 0;
                // Whole pounds from £1,000 on the tiles; the tables keep the pence.
                const text = (figure.type === 'money' || figure.type === 'signedMoney') && figure.value !== null ? moneyShort(figure.value) : cellText(figure.value, figure.type);

                return (
                    <StatCard
                        key={figure.key}
                        label={figure.label}
                        value={<span className={cn(negative && 'text-danger-foreground')}>{text}</span>}
                        delta={changeDelta(figure.change, versus, figure.goodWhen)}
                        hint={
                            figure.previous !== null && figure.change === null && versus
                                ? `${figure.hint ? `${figure.hint} · ` : ''}was ${cellText(figure.previous, figure.type)}`
                                : (figure.hint ?? undefined)
                        }
                    />
                );
            })}
        </div>
    );
}
