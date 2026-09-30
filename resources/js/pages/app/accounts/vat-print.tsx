import { type VatPrintProps } from '@/components/app/accounts/types';
import { VatBoxes } from '@/components/app/accounts/vat-boxes';
import { formatDay } from '@/components/app/pricing/format';
import AppLogoIcon from '@/components/app-logo-icon';
import { money } from '@/components/shared/trading/format';
import { Button } from '@/components/ui/button';
import { Head } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

const PAGE_CSS = '@page { size: A4 portrait; margin: 14mm; } @media print { html, body { background: white !important; } }';

/** The VAT return helper laid out for paper (module 5.5): heading, boxes 1–9 and where the figures come from. */
export default function AccountsVatPrint({ business, shopName, quarter, boxes, position, sources, unreclaimedVat, filters }: VatPrintProps) {
    const back = route('app.accounts.vat', { quarter: quarter.value, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) });

    return (
        <div className="bg-background text-foreground min-h-screen print:bg-white print:text-black">
            <Head title={`VAT return ${quarter.label} (print)`} />
            <style>{PAGE_CSS}</style>

            <div className="bg-card sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-4 py-3 print:hidden">
                <Button variant="ghost" asChild>
                    <a href={back}>
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to the VAT return
                    </a>
                </Button>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" aria-hidden />
                    Print
                </Button>
            </div>

            <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 text-sm print:max-w-none print:gap-4 print:p-0">
                <header className="flex items-start justify-between gap-6 border-b pb-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight print:text-xl">VAT return helper</h1>
                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5">
                            <dt className="text-muted-foreground print:text-neutral-600">Business</dt>
                            <dd className="font-medium">{business}</dd>
                            <dt className="text-muted-foreground print:text-neutral-600">Shop</dt>
                            <dd className="font-medium">{shopName}</dd>
                            <dt className="text-muted-foreground print:text-neutral-600">Period</dt>
                            <dd className="font-medium">
                                {formatDay(quarter.from)} to {formatDay(quarter.to)}
                            </dd>
                        </dl>
                    </div>
                    <AppLogoIcon className="size-10 shrink-0" />
                </header>

                <VatBoxes boxes={boxes} position={position} />

                <section className="break-inside-avoid">
                    <h2 className="mb-2 font-semibold">Where the figures come from</h2>
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b">
                                <th className="py-1.5 font-medium">Source</th>
                                <th className="py-1.5 text-right font-medium">Net</th>
                                <th className="py-1.5 text-right font-medium">VAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sources.map((s) => (
                                <tr key={s.key} className="border-b last:border-0">
                                    <td className="py-1.5">
                                        {s.label} <span className="text-muted-foreground print:text-neutral-600">(boxes {s.boxes})</span>
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">{money(s.net)}</td>
                                    <td className="py-1.5 text-right tabular-nums">{money(s.vat)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <footer className="text-muted-foreground grid gap-1 border-t pt-4 text-xs print:text-neutral-600">
                    {Number(unreclaimedVat) !== 0 && <p>{money(unreclaimedVat)} of VAT on expenses is left out: no VAT receipt is held.</p>}
                    <p>Worked out by Switch &amp; Save from your till and purchase data. Not filed with HMRC: check it, then file through MTD software.</p>
                </footer>
            </main>
        </div>
    );
}
