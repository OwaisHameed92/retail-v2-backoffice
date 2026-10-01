import { type CSSProperties } from 'react';
import { type LabelContent, type LabelOptions, type LabelStock } from './types';

const PT = 0.3528; // mm per point

interface Props {
    stock: LabelStock;
    options: LabelOptions;
    /** Labels already expanded by copies, in print order. */
    labels: LabelContent[];
    /** Used labels left empty at the start of the sheet (A4 only). */
    skip?: number;
    className?: string;
}

/**
 * One printed page (an A4 sheet or a roll label) drawn to scale, the same layout as the PDF
 * (resources/views/labels/sheet-pdf.blade.php). Sizes are millimetres turned into container-width units, so the sheet
 * scales with its box. Labels are printed in black on white, whatever the theme.
 */
export function LabelSheet({ stock, options, labels, skip = 0, className }: Props) {
    const mm = (value: number) => `${(value * 100) / stock.pageWidth}cqw`;
    const slots = Array.from({ length: stock.perPage }, (_, i) => i);
    const start = stock.kind === 'a4' ? Math.min(skip, stock.perPage - 1) : 0;

    return (
        <div
            className={`relative w-full overflow-hidden rounded-sm bg-white shadow-sm ring-1 ring-black/10 ${className ?? ''}`}
            style={{ aspectRatio: `${stock.pageWidth} / ${stock.pageHeight}`, containerType: 'inline-size' }}
            role="img"
            aria-label={`Preview of ${stock.name}`}
        >
            {slots.map((slot) => {
                const label = slot >= start ? labels[slot - start] : undefined;
                const x = stock.left + (slot % stock.cols) * stock.hPitch;
                const y = stock.top + Math.floor(slot / stock.cols) * stock.vPitch;
                const box: CSSProperties = { left: mm(x), top: mm(y), width: mm(stock.width), height: mm(stock.height) };

                return label ? (
                    <div key={slot} className="absolute overflow-hidden text-black" style={box}>
                        <LabelFace label={label} stock={stock} options={options} mm={mm} />
                    </div>
                ) : (
                    <div key={slot} className="absolute rounded-[2px] border border-dashed border-black/10" style={box} />
                );
            })}
        </div>
    );
}

function LabelFace({ label, stock, options, mm }: { label: LabelContent; stock: LabelStock; options: LabelOptions; mm: (v: number) => string }) {
    const s = Math.max(0.75, Math.min(1.8, stock.height / 33.9));
    const tiny = stock.height < 28;
    const pad = 1.6 * s;
    const band = 6 * s;
    const hasBand = options.highlight_offers && options.show_offer_name && label.offer !== null;
    const top = pad + (hasBand ? band : 0);
    const nameSize = (tiny ? 6.5 : 7.5) * s;
    const foot = [
        options.show_pmp ? label.pmp : null,
        options.show_drs ? label.deposit : null,
        options.show_shop_name ? label.shop : null,
        options.show_date ? label.date : null,
    ].filter((part): part is string => Boolean(part));
    const footH = foot.length > 0 ? 2.2 * s : 0;
    const unitH = options.show_unit_price && label.unitPrice ? 2.6 * s : 0;
    const bottom = pad * 0.6;
    const pt = (value: number) => mm(value * PT);

    return (
        <>
            {hasBand && (
                <div
                    className="absolute inset-x-0 top-0 truncate text-center font-bold"
                    style={{ height: mm(band), lineHeight: 1, paddingTop: mm(1.5 * s), fontSize: pt(7.5 * s), background: '#FFD83D' }}
                >
                    {label.offer}
                </div>
            )}
            <div
                className="absolute overflow-hidden font-bold"
                style={{ top: mm(top), left: mm(pad), right: mm(pad), fontSize: pt(nameSize), lineHeight: 1.15, maxHeight: pt((tiny ? 1 : 2) * 1.15 * nameSize) }}
            >
                {label.name}
            </div>
            {!hasBand && options.show_offer_name && label.offer && (
                <div
                    className="absolute truncate font-bold"
                    style={{ top: mm(top + (tiny ? 1 : 2) * 1.15 * 7.5 * s * PT + 0.4), left: mm(pad), right: mm(pad), fontSize: pt(6.5 * s), color: '#B00020' }}
                >
                    {label.offer}
                    {label.offerUntil ? ` · ${label.offerUntil}` : ''}
                </div>
            )}
            <div
                className="absolute font-bold whitespace-nowrap tabular-nums"
                style={{ right: mm(pad), bottom: mm(bottom + footH + unitH), fontSize: pt((tiny ? 17 : 23) * s), lineHeight: 1 }}
            >
                {label.priceText}
            </div>
            {unitH > 0 && (
                <div className="absolute whitespace-nowrap" style={{ right: mm(pad), bottom: mm(bottom + footH), fontSize: pt(5.5 * s) }}>
                    {label.unitPrice}
                </div>
            )}
            {foot.length > 0 && (
                <div className="absolute whitespace-nowrap" style={{ right: mm(pad), bottom: mm(bottom), fontSize: pt(4.3 * s), color: '#333' }}>
                    {foot.join(' · ')}
                </div>
            )}
            {options.show_barcode && label.barcode && !tiny && (
                <div className="absolute" style={{ left: mm(pad), bottom: mm(bottom), width: mm(stock.width * 0.4) }}>
                    <img
                        src={`data:image/svg+xml;utf8,${encodeURIComponent(label.barcode.svg)}`}
                        alt={`Barcode ${label.barcode.text}`}
                        className="block w-full"
                        style={{ height: mm(stock.height * 0.24) }}
                    />
                    <div className="text-center" style={{ fontSize: pt(4.3 * s), letterSpacing: pt(0.4) }}>
                        {label.barcode.text}
                    </div>
                </div>
            )}
        </>
    );
}
