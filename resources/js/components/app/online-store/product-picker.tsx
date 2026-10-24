import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { Check, ImageIcon, LoaderCircle, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { type PickerProduct } from './types';

/**
 * Searches the products the online store shows (OS-8, `app.online-store.product-search`, JSON) and picks one: for a
 * banner's button or the hand-picked featured products. Already chosen products are marked and cannot be picked again.
 */
export function ProductPicker({
    open,
    onOpenChange,
    onPick,
    chosen = [],
    title = 'Choose a product',
    description = 'Products your online store shows at your first collection shop.',
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (product: PickerProduct) => void;
    chosen?: string[];
    title?: string;
    description?: string;
}) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PickerProduct[]>([]);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try {
                const reply = await sendJson<{ products: PickerProduct[] }>(
                    'GET',
                    route('app.online-store.product-search', term.trim() === '' ? {} : { q: term.trim() }),
                    undefined,
                    controller.signal,
                );
                setResults(reply.data?.products ?? []);
                setProblem(reply.ok ? null : reply.message);
                setLoading(false);
            } catch {
                // Aborted: a newer search is on its way.
            }
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term, open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                    <Input
                        autoFocus
                        type="search"
                        aria-label="Search products"
                        placeholder="Name, code or barcode"
                        className="pl-9"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                    />
                </div>
                <div className="-mx-2 max-h-[min(60vh,420px)] overflow-y-auto" aria-live="polite" aria-busy={loading}>
                    {problem && <p className="text-danger-foreground px-2 py-3 text-sm">{problem}</p>}
                    {!problem && !loading && results.length === 0 && (
                        <p className="text-muted-foreground px-2 py-6 text-center text-sm">
                            {term ? 'No products shown online match that.' : 'No products are shown online yet. Add a collection shop first.'}
                        </p>
                    )}
                    {loading && results.length === 0 && (
                        <p className="text-muted-foreground flex items-center justify-center gap-2 py-6 text-sm">
                            <LoaderCircle className="size-4 animate-spin" aria-hidden /> Searching…
                        </p>
                    )}
                    <ul className="grid gap-1">
                        {results.map((product) => {
                            const taken = chosen.includes(product.id);

                            return (
                                <li key={product.id}>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={taken}
                                        onClick={() => onPick(product)}
                                        className="h-auto w-full justify-start gap-3 px-2 py-2 text-left"
                                    >
                                        <span className="bg-muted flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                                            {product.photoUrl ? (
                                                <img src={product.photoUrl} alt="" className="size-full object-contain" />
                                            ) : (
                                                <ImageIcon className="text-muted-foreground size-4" aria-hidden />
                                            )}
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-sm font-medium">{product.name}</span>
                                            <span className="text-muted-foreground truncate text-xs">
                                                {[
                                                    product.department,
                                                    product.price ? formatMoney(product.price) + (product.isWeighed ? '/kg' : '') : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </span>
                                        {taken && <Check className="text-success size-4" aria-label="Already chosen" />}
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    );
}
