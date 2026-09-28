import { useEffect, useRef } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (element: HTMLElement, options: Record<string, unknown>) => string;
            reset: (widgetId?: string) => void;
            remove: (widgetId: string) => void;
        };
    }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function loadScript(): Promise<void> {
    if (window.turnstile) {
        return Promise.resolve();
    }
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT_SRC}"]`);
    if (existing) {
        return new Promise((resolve) => existing.addEventListener('load', () => resolve(), { once: true }));
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Turnstile failed to load'));
        document.head.appendChild(script);
    });
}

/**
 * Cloudflare Turnstile check (module 1.10). Calls `onToken` with a token, or null when it expires or fails.
 * Bump `resetKey` to get a fresh token after a refused request (tokens are single use).
 */
export function TurnstileWidget({ siteKey, onToken, resetKey }: { siteKey: string; onToken: (token: string | null) => void; resetKey: number }) {
    const container = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);
    const callback = useRef(onToken);

    useEffect(() => {
        callback.current = onToken;
    }, [onToken]);

    useEffect(() => {
        let cancelled = false;

        loadScript()
            .then(() => {
                if (cancelled || !container.current || !window.turnstile) {
                    return;
                }
                widgetId.current = window.turnstile.render(container.current, {
                    sitekey: siteKey,
                    theme: 'light',
                    callback: (token: string) => callback.current(token),
                    'expired-callback': () => callback.current(null),
                    'error-callback': () => callback.current(null),
                });
            })
            .catch(() => callback.current(null));

        return () => {
            cancelled = true;
            if (widgetId.current && window.turnstile) {
                window.turnstile.remove(widgetId.current);
            }
            widgetId.current = null;
        };
    }, [siteKey]);

    useEffect(() => {
        if (resetKey > 0 && widgetId.current && window.turnstile) {
            window.turnstile.reset(widgetId.current);
            callback.current(null);
        }
    }, [resetKey]);

    return <div ref={container} className="min-h-[65px]" />;
}
