import { type IssuedKey } from '@/components/admin/licences/types';

export interface RevealRequest {
    keys: IssuedKey[];
    /** Optional heading, e.g. "Kiosk added". Defaults to "Licence key created". */
    title?: string;
}

type Listener = (request: RevealRequest) => void;

const listeners = new Set<Listener>();

/**
 * Show freshly created licence keys in the one-time "Licence key created" dialog (mounted once in AdminLayout).
 * The keys live only in that dialog's state and are dropped when it closes.
 */
export function revealLicenceKeys(request: RevealRequest): void {
    if (request.keys.length === 0) {
        return;
    }
    listeners.forEach((listener) => listener(request));
}

export function onRevealLicenceKeys(listener: Listener): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}
