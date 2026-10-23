import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { formatDate, formatTime } from '@/lib/country';
import { CheckCheck, PackageCheck, ShoppingBasket, type LucideIcon } from 'lucide-react';
import { type OrderCard, type OrderStatus } from './types';

export const STATUS_LABELS: Record<OrderStatus, string> = {
    new: 'New',
    picking: 'Picking',
    ready: 'Ready to collect',
    collected: 'Collected',
    cancelled: 'Cancelled',
};

const STATUS_TONES: StatusToneMap = { new: 'info', picking: 'warning', ready: 'success', collected: 'neutral', cancelled: 'danger' };

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
    return <StatusBadge status={status} label={STATUS_LABELS[status]} tones={STATUS_TONES} />;
}

/** The one main move for an open order: what the button says and where it takes the order. */
export interface NextStep {
    to: Exclude<OrderStatus, 'new' | 'cancelled'>;
    label: string;
    icon: LucideIcon;
}

export const NEXT_STEP: Partial<Record<OrderStatus, NextStep>> = {
    new: { to: 'picking', label: 'Start picking', icon: ShoppingBasket },
    picking: { to: 'ready', label: 'Mark ready', icon: PackageCheck },
    ready: { to: 'collected', label: 'Collected', icon: CheckCheck },
};

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
