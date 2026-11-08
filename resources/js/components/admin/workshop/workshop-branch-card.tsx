import { RevealSecretDialog } from '@/components/admin/partners/partner-dialogs';
import { formatDateTimeShort } from '@/components/admin/tenants/format';
import { WORKSHOP_STATUS_TONES, type WorkshopBranchCard as Card } from '@/components/admin/workshop/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { showToast } from '@/components/shared/toaster';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { sendJson } from '@/lib/http';
import { Link, router } from '@inertiajs/react';
import { KeyRound, ListChecks, Plug, Unplug } from 'lucide-react';
import { useState } from 'react';

interface Props {
    tenantId: string;
    card: Card;
    canManage: boolean;
}

function auth(card: Card): string {
    const parts = [card.oauth ? 'OAuth (approved by the owner)' : null, card.apiKey ? `API key ${card.apiKey.masked}` : null].filter(Boolean);

    return parts.length > 0 ? parts.join(' · ') : 'None yet';
}

/** One shop's "Workshop integration (BikeBook)" card on the admin tenant page (W5). */
export function WorkshopBranchCard({ tenantId, card, canManage }: Props) {
    const [key, setKey] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const params = [tenantId, card.branchId];
    const live = card.status === 'connected' || card.status === 'failed' || card.status === 'pending';
    const canConnect = canManage && card.addOn && card.branchActive && !live;

    const visit = (method: 'post' | 'put' | 'delete', name: string, data: Record<string, boolean> = {}) =>
        new Promise<void>((resolve) => {
            router.visit(route(name, params), {
                method,
                data,
                preserveScroll: true,
                // Refusals (no add-on, still connected…) come back as validation errors: show them.
                onError: (errors) => showToast(Object.values(errors)[0] ?? 'That did not work. Try again.', 'error'),
                onFinish: () => resolve(),
            });
        });

    const createKey = async () => {
        setBusy(true);
        const result = await sendJson<{ key: string }>('POST', route('admin.tenants.branches.workshop.key.store', params));
        setBusy(false);

        if (!result.ok || !result.data) {
            showToast(result.message ?? 'The API key could not be created.', 'error');

            return;
        }
        setKey(result.data.key);
        router.reload({ only: ['workshop', 'activity'] });
    };

    return (
        <SectionCard
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {card.branchName}
                    <StatusBadge status={card.status} label={card.statusLabel} tone={WORKSHOP_STATUS_TONES[card.status]} />
                    {!card.branchActive && <Badge variant="neutral">Shop closed</Badge>}
                </span>
            }
            description="BikeBook reads this shop's products, stock and customers and sends repair jobs to its till."
            actions={
                card.connectionId ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={route('admin.tenants.branches.workshop.log', params)}>
                            <ListChecks aria-hidden />
                            Sync log
                        </Link>
                    </Button>
                ) : undefined
            }
            footer={
                canManage ? (
                    <div className="flex w-full flex-wrap justify-end gap-2">
                        {canConnect && (
                            <Button size="sm" variant="outline" onClick={() => visit('post', 'admin.tenants.branches.workshop.connect')}>
                                <Plug aria-hidden />
                                Connect
                            </Button>
                        )}
                        {card.addOn && card.branchActive && (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline" disabled={busy}>
                                        <KeyRound aria-hidden />
                                        {card.apiKey ? 'Replace API key' : 'Create API key'}
                                    </Button>
                                }
                                title={card.apiKey ? `Replace ${card.branchName}'s BikeBook key?` : `Create a BikeBook key for ${card.branchName}?`}
                                description={
                                    card.apiKey
                                        ? 'The current key stops working at once. Give BikeBook the new key.'
                                        : 'The key connects the shop. It is shown once: give it to BikeBook (header ApiKey).'
                                }
                                confirmLabel={card.apiKey ? 'Replace key' : 'Create key'}
                                onConfirm={createKey}
                            />
                        )}
                        {card.apiKey && (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline">
                                        Revoke key
                                    </Button>
                                }
                                title={`Revoke ${card.branchName}'s BikeBook key?`}
                                description="BikeBook's calls with this key get 401 from now on."
                                confirmLabel="Revoke key"
                                destructive
                                onConfirm={() => visit('delete', 'admin.tenants.branches.workshop.key.destroy')}
                            />
                        )}
                        {live && (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <Unplug aria-hidden />
                                        Disconnect
                                    </Button>
                                }
                                title={`Disconnect BikeBook from ${card.branchName}?`}
                                description="Tokens and key are revoked at once, BikeBook's calls get 401 and its webhook stops. Orders already on the till stay there."
                                confirmLabel="Disconnect"
                                destructive
                                onConfirm={() => visit('post', 'admin.tenants.branches.workshop.disconnect')}
                            />
                        )}
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-5">
                <div className="flex items-start gap-3">
                    <Checkbox
                        id={`workshop-addon-${card.branchId}`}
                        checked={card.addOn}
                        disabled={!canManage || (card.addOn && live)}
                        onCheckedChange={(value) => visit('put', 'admin.tenants.branches.workshop.add-on', { on: value === true })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor={`workshop-addon-${card.branchId}`}>{card.addOnLabel} in this shop's licence</Label>
                        <p className="text-muted-foreground text-sm">
                            {card.addOn
                                ? live
                                    ? 'Disconnect BikeBook before taking it off. No price yet.'
                                    : 'The shop’s tills get it at their next licence check. No price yet.'
                                : 'Not in this shop’s licence. Contact Switch & Save to add it.'}
                        </p>
                    </div>
                </div>

                {card.lastError && (
                    <Alert variant="destructive">
                        <AlertDescription>{card.lastError}</AlertDescription>
                    </Alert>
                )}

                <DescriptionList
                    layout="grid"
                    items={[
                        {
                            label: 'Connected since',
                            value: card.status === 'connected' || card.status === 'failed' ? formatDateTimeShort(card.connectedAt) : null,
                        },
                        { label: 'Last BikeBook call', value: card.lastCallAt ? formatDateTimeShort(card.lastCallAt) : null },
                        { label: 'Sign-in', value: auth(card) },
                        {
                            label: 'Webhook to BikeBook',
                            value: card.webhook
                                ? `${card.webhook.enabled ? 'On' : 'Off'} · ${card.webhook.subscriptions.join(', ') || 'no events'}`
                                : null,
                        },
                        { label: 'Products in scope', value: card.counts ? card.counts.productsInScope.toLocaleString('en-GB') : null },
                        { label: 'Customers shared', value: card.counts ? card.counts.customers.toLocaleString('en-GB') : null },
                        { label: 'Open workshop orders', value: card.counts ? card.counts.openOrders.toLocaleString('en-GB') : null },
                        { label: 'Failed webhooks', value: card.counts ? card.counts.failedWebhooks.toLocaleString('en-GB') : null },
                        ...(card.status === 'disconnected' ? [{ label: 'Disconnected', value: formatDateTimeShort(card.disconnectedAt) }] : []),
                    ]}
                />
            </div>

            <RevealSecretDialog
                value={key}
                title="BikeBook API key created"
                description={`Give this key to BikeBook for ${card.branchName}. It is shown once; we keep only the last 4 characters.`}
                onClose={() => setKey(null)}
            />
        </SectionCard>
    );
}
