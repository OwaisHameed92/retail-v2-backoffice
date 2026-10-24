import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Check, MoreHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import { type OrderLine } from './types';

/**
 * Audit OSA-18: change one line while picking: not available, a new quantity, or back as ordered. The totals follow
 * on the server (ChangeOrderLine).
 */
export function LineChange({ orderId, line }: { orderId: string; line: OrderLine }) {
    const [editing, setEditing] = useState(false);
    const [quantity, setQuantity] = useState(line.quantity);
    const [error, setError] = useState<string | null>(null);
    const send = (action: 'unavailable' | 'changed' | 'restore', qty?: string) =>
        router.post(
            route('app.online-orders.line', [orderId, line.id]),
            { action, quantity: qty ?? null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(false);
                    setError(null);
                },
                onError: (errors) => setError(errors.quantity ?? errors.line ?? errors.action ?? 'That did not work.'),
            },
        );

    if (editing) {
        return (
            <div className="flex flex-col items-end gap-1">
                <div className="flex items-center gap-1">
                    <Input
                        className="h-8 w-20 text-right tabular-nums"
                        inputMode="decimal"
                        aria-label={`New quantity of ${line.name}${line.isWeighed ? ' in kg' : ''}`}
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                    />
                    <Button size="icon" variant="ghost" className="size-8" aria-label="Save quantity" onClick={() => send('changed', quantity)}>
                        <Check className="size-4" />
                    </Button>
                    <Button size="icon" variant="ghost" className="size-8" aria-label="Cancel" onClick={() => setEditing(false)}>
                        <X className="size-4" />
                    </Button>
                </div>
                {error && <p className="text-danger-foreground text-xs">{error}</p>}
            </div>
        );
    }

    return (
        <div className="flex flex-col items-end gap-1">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="icon" variant="ghost" className="size-8" aria-label={`Change ${line.name}`}>
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {line.outcome !== 'unavailable' && <DropdownMenuItem onSelect={() => send('unavailable')}>Not available</DropdownMenuItem>}
                    <DropdownMenuItem onSelect={() => setEditing(true)}>
                        {line.isWeighed ? 'Change the weight' : 'Change the quantity'}
                    </DropdownMenuItem>
                    {line.outcome !== null && <DropdownMenuItem onSelect={() => send('restore')}>Back as ordered</DropdownMenuItem>}
                </DropdownMenuContent>
            </DropdownMenu>
            {error && <p className="text-danger-foreground text-xs">{error}</p>}
        </div>
    );
}
