import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type ToastType = 'success' | 'error';

interface ToastItem {
    id: string;
    type: ToastType;
    message: string;
}

interface ToastPageProps {
    /** Session flash shared on every page: `->with('success', ...)` / `->with('error', ...)`. */
    flash?: { success?: string | null; error?: string | null };
    /** Page prop some controllers pass: `{ id, type, message }`. */
    toast?: { id: string; type: ToastType; message: string } | null;
    [key: string]: unknown;
}

const DISMISS_AFTER_MS = 5000;

/** Validation error keys from actions without a form (status changes, deactivate…) that should appear as toasts. */
const DEFAULT_ERROR_KEYS = ['status', 'branch', 'branch_id', 'register', 'user', 'user_id', 'plan'];

type Listener = (toast: ToastItem) => void;
const listeners = new Set<Listener>();
const seen = new Set<string>();

/** Show a toast from anywhere in the client, e.g. after a failed confirm dialog. */
export function showToast(message: string, type: ToastType = 'success'): void {
    const toast: ToastItem = { id: `${Date.now()}-${Math.random().toString(36).slice(2)}`, type, message };
    listeners.forEach((listener) => listener(toast));
}

/**
 * The one toaster for the whole app, mounted once in AdminLayout and AppLayout. Shows session flash messages,
 * a page's `toast` prop, action validation errors, and anything sent with `showToast()`. Bottom-right,
 * announced to screen readers, dismisses after 5 seconds, at most three at a time.
 */
export function Toaster({ errorKeys = DEFAULT_ERROR_KEYS }: { errorKeys?: string[] }) {
    const page = usePage<ToastPageProps>();
    const { flash, toast, errors } = page.props;
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const timers = useRef(new Map<string, number>());

    useEffect(() => {
        const activeTimers = timers.current;
        const add: Listener = (next) => {
            if (seen.has(next.id)) {
                return;
            }
            seen.add(next.id);
            setToasts((current) => [...current.slice(-2), next]);
            activeTimers.set(
                next.id,
                window.setTimeout(() => setToasts((current) => current.filter((t) => t.id !== next.id)), DISMISS_AFTER_MS),
            );
        };
        listeners.add(add);

        return () => {
            listeners.delete(add);
            activeTimers.forEach((timer) => window.clearTimeout(timer));
            activeTimers.clear();
        };
    }, []);

    useEffect(() => {
        if (toast) {
            listeners.forEach((listener) => listener(toast));
        }
    }, [toast]);

    const errorBag = (errors ?? {}) as Record<string, unknown>;
    const actionError = errorKeys
        .map((key) => errorBag[key])
        .filter((message): message is string => typeof message === 'string' && message !== '')
        .join(' ');

    // Flash and action errors arrive once per visit; key them by the visit so the same text can show again later.
    useEffect(() => {
        const visit = `${page.url}-${Date.now()}`;
        if (flash?.success) showToast(flash.success, 'success');
        if (flash?.error) showToast(flash.error, 'error');
        if (actionError) listeners.forEach((listener) => listener({ id: `${visit}-err`, type: 'error', message: actionError }));
    }, [flash, actionError, page.url]);

    const dismiss = (id: string) => {
        window.clearTimeout(timers.current.get(id));
        setToasts((current) => current.filter((t) => t.id !== id));
    };

    return (
        <div
            aria-live="polite"
            role="status"
            className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2"
        >
            {toasts.map((item) => (
                <div
                    key={item.id}
                    className={cn(
                        'bg-card text-card-foreground pointer-events-auto flex items-start gap-3 rounded-lg border p-3 pr-2 text-sm shadow-lg',
                        'animate-in fade-in slide-in-from-bottom-2',
                        item.type === 'error' && 'border-destructive/40',
                    )}
                >
                    {item.type === 'error' ? (
                        <CircleAlert className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden />
                    ) : (
                        <CircleCheck className="text-success mt-0.5 size-4 shrink-0" aria-hidden />
                    )}
                    <p className="flex-1 leading-snug">{item.message}</p>
                    <Button variant="ghost" size="icon" className="-my-1 size-7 shrink-0" onClick={() => dismiss(item.id)} aria-label="Dismiss">
                        <X className="size-4" />
                    </Button>
                </div>
            ))}
        </div>
    );
}

export default Toaster;
