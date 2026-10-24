import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { formatDate, formatTime } from '@/lib/country';
import { CheckCheck, PackageCheck, ShoppingBasket, Truck, type LucideIcon } from 'lucide-react';
import { type Fulfilment, type OrderCard, type OrderStatus } from './types';

export const STATUS_LABELS: Record<OrderStatus, string> = {
    new: 'New',
    picking: 'Picking',
    ready: 'Ready to collect',
    outForDelivery: 'Out for delivery',
    collected: 'Collected',
    delivered: 'Delivered',
    notCollected: 'Not collected',
    cancelled: 'Cancelled',
};

/** A stage's name for this order's method (a delivery is "Ready to go out", "Not delivered"). */
export function statusLabel(status: OrderStatus, fulfilment: Fulfilment = 'collection'): string {
    if (fulfilment === 'delivery' && status === 'ready') {
        return 'Ready to go out';
    }
    if (fulfilment === 'delivery' && status === 'notCollected') {
        return 'Not delivered';
    }

    return STATUS_LABELS[status];
}

const STATUS_TONES: StatusToneMap = {
    new: 'info',
    picking: 'warning',
    ready: 'success',
    outForDelivery: 'info',
    collected: 'neutral',
    delivered: 'neutral',
    notCollected: 'warning',
    cancelled: 'danger',
};

export function OrderStatusBadge({ status, fulfilment = 'collection' }: { status: OrderStatus; fulfilment?: Fulfilment }) {
    return <StatusBadge status={status} label={statusLabel(status, fulfilment)} tones={STATUS_TONES} />;
}

/** Finished: in the Done column, no more moves. */
export const isDone = (status: OrderStatus) => ['collected', 'delivered', 'notCollected', 'cancelled'].includes(status);

/** The one main move for an open order: what the button says and where it takes the order. */
export interface NextStep {
    to: Exclude<OrderStatus, 'new' | 'cancelled'>;
    label: string;
    icon: LucideIcon;
    /** Final: asks first. */
    confirm?: boolean;
}

const COLLECTION_STEPS: Partial<Record<OrderStatus, NextStep>> = {
    new: { to: 'picking', label: 'Start picking', icon: ShoppingBasket },
    picking: { to: 'ready', label: 'Mark ready', icon: PackageCheck },
    ready: { to: 'collected', label: 'Collected', icon: CheckCheck, confirm: true },
};

const DELIVERY_STEPS: Partial<Record<OrderStatus, NextStep>> = {
    new: { to: 'picking', label: 'Start picking', icon: ShoppingBasket },
    picking: { to: 'ready', label: 'Mark packed', icon: PackageCheck },
    ready: { to: 'outForDelivery', label: 'Out for delivery', icon: Truck },
    outForDelivery: { to: 'delivered', label: 'Delivered', icon: CheckCheck, confirm: true },
};

export function nextStep(status: OrderStatus, fulfilment: Fulfilment): NextStep | undefined {
    return (fulfilment === 'delivery' ? DELIVERY_STEPS : COLLECTION_STEPS)[status];
}

export const CANCEL_REASONS = ['Sorry, some items are out of stock.', 'Sorry, the shop has had to close early.', 'Cancelled at your request.'];

/** "10:00–10:30" (shop time). */
export function slotTime(order: Pick<OrderCard, 'collectFrom' | 'collectTo'>): string {
    return `${formatTime(order.collectFrom)}–${formatTime(order.collectTo)}`;
}

/** "Mon 2 Nov, 10:00–10:30" (shop time). */
export function slotDayTime(order: Pick<OrderCard, 'collectFrom' | 'collectTo'>): string {
    return `${formatDate(order.collectFrom, { weekday: 'short', year: undefined })}, ${slotTime(order)}`;
}

/** "Mon 2 Nov" for a calendar day ("2026-11-02"). */
export function dayLabel(day: string): string {
    return formatDate(day, { weekday: 'short', year: undefined });
}

/** "1 item", "6 items". */
export function itemsLabel(count: number): string {
    return `${count} ${count === 1 ? 'item' : 'items'}`;
}
