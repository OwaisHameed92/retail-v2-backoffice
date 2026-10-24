import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { ExternalLink, Eye, Power, Rocket } from 'lucide-react';
import { useState } from 'react';
import { putSwitch } from './status-panel';
import { type StoreSettings } from './types';

/**
 * The store builder's header buttons (OS-3, split out of builder.tsx for OSA-35): preview the live store, and go
 * live or take it offline (with a confirmation). Going live waits for saved changes; the server says what is missing.
 */
export function BuilderActions({ store, canEdit, dirty }: { store: StoreSettings; canEdit: boolean; dirty: boolean }) {
    const [switching, setSwitching] = useState(false);
    const setLive = (on: boolean) =>
        new Promise<void>((resolve) => {
            setSwitching(true);
            putSwitch('app.online-store.live', on, () => {
                setSwitching(false);
                resolve();
            });
        });

    return (
        <>
            {store.isLive ? (
                <Button variant="outline" asChild>
                    <a href={store.address} target="_blank" rel="noopener noreferrer">
                        <ExternalLink />
                        Preview store
                    </a>
                </Button>
            ) : (
                <Button variant="outline" disabled title="The store opens once it is live">
                    <Eye />
                    Preview store
                </Button>
            )}
            {canEdit &&
                (store.isLive ? (
                    <ConfirmDialog
                        trigger={
                            <Button variant="outline" disabled={switching}>
                                <Power />
                                Take offline
                            </Button>
                        }
                        title="Take your store offline?"
                        description={`${store.address} stops opening and customers cannot place orders. Orders already placed stay on the Online orders board, and customers can still open them from their emails.`}
                        confirmLabel="Take offline"
                        destructive
                        onConfirm={() => setLive(false)}
                    />
                ) : (
                    <ConfirmDialog
                        trigger={
                            <Button disabled={switching || dirty} title={dirty ? 'Save your changes first' : undefined}>
                                <Rocket />
                                Go live
                            </Button>
                        }
                        title="Go live?"
                        description={`Customers can open ${store.address} and ${store.takingOrders ? 'place orders' : 'look around (orders are paused)'}. Share the address on your receipts, social media and shop window.`}
                        confirmLabel="Go live"
                        onConfirm={() => setLive(true)}
                    />
                ))}
        </>
    );
}
