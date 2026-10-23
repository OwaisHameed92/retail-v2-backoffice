import { Link, router } from '@inertiajs/react';
import { Loader2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { money, priceLabel, quantityLabel } from './format';
import { ProductPhoto } from './product-photo';
import { QtyStepper } from './qty-stepper';
import { type BasketLine } from './types';

/** One basket line: photo, name, price, quantity (sent at once), line total and remove. */
export function BasketLineRow({ line, editable }: { line: BasketLine; editable: boolean }) {
    const { product } = line;
    const [quantity, setQuantity] = useState(line.quantity);
    const [busy, setBusy] = useState(false);

    useEffect(() => setQuantity(line.quantity), [line.quantity]);

    const change = (next: string) => {
        setQuantity(next);
        router.patch(line.updateUrl, { quantity: next }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };
    const remove = () => router.delete(line.removeUrl, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-[24px] bg-white p-2.5 pr-3 sm:flex-nowrap">
            <Link href={product.url} tabIndex={-1} aria-hidden="true" className="w-20 shrink-0 overflow-hidden rounded-[18px] sm:w-24">
                <ProductPhoto name={product.name} url={product.photoUrl} alt="" rounded="rounded-[18px]" size="sm" />
            </Link>
            <div className="flex min-w-0 flex-[1_1_160px] flex-col gap-1">
                <Link href={product.url} className="text-ink -my-2.5 py-2.5 leading-snug font-bold underline-offset-4 hover:underline">
                    {product.name}
                </Link>
                <span className="text-muted text-[13px]">
                    {priceLabel(product)}
                    {product.isWeighed && ' · estimated, final price on the scale'}
                </span>
            </div>
            <div className="ml-auto flex items-center gap-2 sm:ml-0">
                {editable ? (
                    <QtyStepper
                        value={quantity}
                        onChange={change}
                        step={product.step}
                        min={product.step}
                        max={product.max}
                        weighed={product.isWeighed}
                        name={product.name}
                        tone="paper"
                        disabled={busy}
                    />
                ) : (
                    <span className="font-bold">{quantityLabel(quantity, product.isWeighed)}</span>
                )}
            </div>
            <span className="font-display min-w-[84px] text-right text-2xl font-extrabold tracking-[-0.03em]">
                {busy ? <Loader2 aria-label="Updating" className="ml-auto size-5 animate-spin" /> : money(line.lineTotal)}
            </span>
            {editable && (
                <button
                    type="button"
                    onClick={remove}
                    disabled={busy}
                    aria-label={`Remove ${product.name}`}
                    className="text-muted hover:bg-paper hover:text-ink flex size-11 shrink-0 items-center justify-center rounded-full"
                >
                    <X aria-hidden="true" className="size-5" />
                </button>
            )}
        </li>
    );
}
