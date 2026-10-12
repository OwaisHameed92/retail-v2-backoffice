import { PartnerFormDialog, RevealSecretDialog } from '@/components/admin/partners/partner-dialogs';
import { type PartnerKeyRow, type PartnerSettingsRow } from '@/components/admin/partners/types';
import { formatDate, formatDateTimeShort } from '@/components/admin/tenants/format';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { sendJson } from '@/lib/http';
import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, LoaderCircle, Pencil, PlugZap, Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';

interface PartnerSettingsProps {
    partners: PartnerSettingsRow[];
    apiBase: string;
}

type Reveal = { value: string; title: string; description: string };

function KeyRow({ partner, item }: { partner: PartnerSettingsRow; item: PartnerKeyRow }) {
    return (
        <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
            <span className="grid min-w-0 gap-0.5">
                <span className="flex items-center gap-2 text-sm font-medium">
                    <code className="font-mono">{item.masked}</code>
                    {item.name && <span className="text-muted-foreground font-normal">{item.name}</span>}
                </span>
                <span className="text-muted-foreground text-xs">
                    Created {formatDate(item.createdAt)} · {item.lastUsedAt ? `last used ${formatDateTimeShort(item.lastUsedAt)}` : 'never used'}
                    {item.revokedAt && ` · revoked ${formatDate(item.revokedAt)}`}
                </span>
            </span>
            {item.revokedAt ? (
                <StatusBadge status="revoked" />
            ) : (
                <ConfirmDialog
                    trigger={
                        <Button type="button" size="sm" variant="outline">
                            Revoke
                        </Button>
                    }
                    title={`Revoke ${item.masked}?`}
                    description={`${partner.name}'s calls with this key are refused from now on. Give them a new key first if they still need access.`}
                    confirmLabel="Revoke key"
                    destructive
                    onConfirm={() =>
                        new Promise<void>((resolve) =>
                            router.delete(route('admin.settings.partners.keys.destroy', [partner.id, item.id]), {
                                preserveScroll: true,
                                onFinish: () => resolve(),
                            }),
                        )
                    }
                />
            )}
        </li>
    );
}

function PartnerCard({ partner, onReveal }: { partner: PartnerSettingsRow; onReveal: (reveal: Reveal) => void }) {
    const [editing, setEditing] = useState(false);
    const [keyName, setKeyName] = useState('');
    const [busy, setBusy] = useState<'key' | 'secret' | null>(null);

    const createKey = async () => {
        setBusy('key');
        const result = await sendJson<{ key: string }>('POST', route('admin.settings.partners.keys.store', partner.id), { name: keyName });
        setBusy(null);
        if (!result.ok || !result.data) {
            showToast(result.message ?? 'The key was not created.', 'error');
            return;
        }
        setKeyName('');
        onReveal({
            value: result.data.key,
            title: 'API key created',
            description: `Give this key to ${partner.name} (Authorization: Bearer …). It is shown only now: we keep a fingerprint and its last 4 characters.`,
        });
        router.reload({ only: ['partners'] });
    };

    const createSecret = async () => {
        setBusy('secret');
        const result = await sendJson<{ secret: string }>('POST', route('admin.settings.partners.webhook-secret', partner.id));
        setBusy(null);
        if (!result.ok || !result.data) {
            showToast(result.message ?? 'The secret was not created.', 'error');
            return;
        }
        onReveal({
            value: result.data.secret,
            title: 'Webhook signing secret',
            description: `Give this to ${partner.name}: they check X-Partner-Signature with it. It is shown only now; webhooks are signed with it from now on.`,
        });
        router.reload({ only: ['partners'] });
    };

    return (
        <SectionCard
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {partner.name}
                    <StatusBadge status={partner.isActive ? 'active' : 'inactive'} />
                </span>
            }
            description={
                <>
                    {partner.requests} {partner.requests === 1 ? 'request' : 'requests'}
                    {partner.pendingRequests > 0 && (
                        <>
                            {' · '}
                            <Link
                                href={route('admin.partner-requests.index', { status: 'pendingReview', partner: partner.id })}
                                className="text-primary hover:underline"
                            >
                                {partner.pendingRequests} waiting for review
                            </Link>
                        </>
                    )}
                </>
            }
            actions={
                <Button type="button" size="sm" variant="outline" onClick={() => setEditing(true)}>
                    <Pencil />
                    Edit
                </Button>
            }
        >
            <div className="grid gap-6">
                <div className="grid gap-2">
                    <h3 className="text-sm font-semibold">Webhooks</h3>
                    <p className="text-muted-foreground text-sm break-all">
                        {partner.webhookUrl ?? 'No webhook URL yet: updates wait (up to 24 hours) until one is set.'}
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-sm">
                            Signing secret:{' '}
                            {partner.hasSecret ? (
                                <code className="font-mono">whsec_••••{partner.secretEnding}</code>
                            ) : (
                                <span className="text-muted-foreground">not set</span>
                            )}
                        </span>
                        {partner.hasSecret ? (
                            <ConfirmDialog
                                trigger={
                                    <Button type="button" size="sm" variant="outline" disabled={busy !== null}>
                                        <RefreshCw />
                                        Rotate secret
                                    </Button>
                                }
                                title={`Rotate ${partner.name}'s signing secret?`}
                                description="Webhooks are signed with the new secret at once; the old one stops working. Give them the new one straight away."
                                confirmLabel="Rotate secret"
                                onConfirm={createSecret}
                            />
                        ) : (
                            <Button type="button" size="sm" variant="outline" onClick={createSecret} disabled={busy !== null}>
                                {busy === 'secret' && <LoaderCircle className="size-4 animate-spin" />}
                                Create secret
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-2">
                    <h3 className="text-sm font-semibold">API keys</h3>
                    {partner.keys.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No keys yet. Create one and give it to {partner.name}.</p>
                    ) : (
                        <ul className="divide-y">
                            {partner.keys.map((item) => (
                                <KeyRow key={item.id} partner={partner} item={item} />
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div className="grid gap-1.5 sm:w-64">
                            <Label htmlFor={`key-name-${partner.id}`}>Key name</Label>
                            <Input
                                id={`key-name-${partner.id}`}
                                placeholder="Live, Staging…"
                                maxLength={80}
                                value={keyName}
                                onChange={(e) => setKeyName(e.target.value)}
                            />
                        </div>
                        <Button type="button" onClick={createKey} disabled={busy !== null}>
                            {busy === 'key' ? <LoaderCircle className="size-4 animate-spin" /> : <KeyRound />}
                            Create API key
                        </Button>
                    </div>
                </div>
            </div>
            <PartnerFormDialog partner={partner} open={editing} onOpenChange={setEditing} />
        </SectionCard>
    );
}

/**
 * Admin → Settings → Partners (owner only): resellers that send signed deals through the partner API, their webhook
 * URL and signing secret, and their API keys (shown once).
 */
export default function PartnerSettings({ partners, apiBase }: PartnerSettingsProps) {
    const [adding, setAdding] = useState(false);
    const [reveal, setReveal] = useState<Reveal | null>(null);

    return (
        <AdminLayout>
            <Head title="Partners" />
            <PageHeader
                title="Partners"
                description="Resellers that send us signed EPOS deals. Their requests wait in Partner requests for review."
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        Add partner
                    </Button>
                }
            />

            <SectionCard title="Partner API" description="Give partners this address with their API key and webhook secret.">
                <code className="bg-muted block rounded-lg border p-3 font-mono text-sm break-all">{apiBase}</code>
            </SectionCard>

            {partners.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={PlugZap}
                        title="No partners yet"
                        body="Add Zivaro, set its webhook URL, then create its signing secret and API key."
                        action={
                            <Button onClick={() => setAdding(true)}>
                                <Plus />
                                Add partner
                            </Button>
                        }
                    />
                </SectionCard>
            ) : (
                partners.map((partner) => <PartnerCard key={partner.id} partner={partner} onReveal={setReveal} />)
            )}

            <PartnerFormDialog open={adding} onOpenChange={setAdding} />
            <RevealSecretDialog
                value={reveal?.value ?? null}
                title={reveal?.title ?? ''}
                description={reveal?.description ?? ''}
                onClose={() => setReveal(null)}
            />
        </AdminLayout>
    );
}
