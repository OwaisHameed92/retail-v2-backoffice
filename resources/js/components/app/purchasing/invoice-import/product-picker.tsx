import { cost } from '@/components/app/purchasing/format';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type InvoiceReviewProps } from './types';

interface ProductPickerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    description: string;
    results: InvoiceReviewProps['results'];
    search: string | null;
    onPick: (product: InvoiceReviewProps['results'][number] | null) => void;
}

/** Search the catalogue by name, SKU or barcode and point an invoice line at a product (or at none). */
export function ProductPicker({ open, onOpenChange, description, results, search, onPick }: ProductPickerProps) {
    const [query, setQuery] = useState(search ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    const find = (value: string) => {
        setQuery(value);
        clearTimeout(timer.current);
        timer.current = setTimeout(
            () =>
                router.get(
                    window.location.pathname,
                    { q: value },
                    { only: ['results', 'search'], preserveState: true, preserveScroll: true, replace: true },
                ),
            300,
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Choose the product</DialogTitle>
                    <DialogDescription className="break-words">For “{description}”.</DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        autoFocus
                        className="pl-9"
                        placeholder="Name, SKU or barcode"
                        value={query}
                        onChange={(e) => find(e.target.value)}
                        aria-label="Search products"
                    />
                </div>
                <ul className="divide-border max-h-72 divide-y overflow-y-auto rounded-md border" aria-label="Products found">
                    {results.length === 0 ? (
                        <li className="text-muted-foreground px-3 py-6 text-center text-sm">
                            {query ? 'No products found. Try another name, SKU or barcode.' : 'Type to search your catalogue.'}
                        </li>
                    ) : (
                        results.map((product) => (
                            <li key={product.id}>
                                <button
                                    type="button"
                                    className="hover:bg-muted focus-visible:bg-muted flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm outline-none"
                                    onClick={() => onPick(product)}
                                >
                                    <span className="grid min-w-0">
                                        <span className="truncate font-medium">{product.name}</span>
                                        {product.sku && <span className="text-muted-foreground font-mono text-xs">{product.sku}</span>}
                                    </span>
                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">Cost {cost(product.costPrice)}</span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
                <div className="flex justify-end">
                    <Button type="button" variant="ghost" onClick={() => onPick(null)}>
                        No product (leave unmatched)
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
