import { CheckField, ReadOnlyNotice } from '@/components/app/setup/fields';
import { StaffTabs } from '@/components/app/setup/till-list-tabs';
import { type RoleEditorProps, type TillRoleRow } from '@/components/app/setup/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';

function RoleList({ roles, selected }: { roles: TillRoleRow[]; selected: string }) {
    return (
        <Card className="p-2">
            <nav aria-label="Till roles" className="grid gap-1">
                {roles.map((role) => (
                    <button
                        key={role.id}
                        type="button"
                        onClick={() => router.get(route('app.staff.roles'), { role: role.id }, { preserveScroll: true, preserveState: false })}
                        aria-current={role.id === selected ? 'page' : undefined}
                        className={cn(
                            'focus-visible:ring-ring/40 flex items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm outline-none focus-visible:ring-2',
                            role.id === selected ? 'bg-primary-soft text-accent-foreground font-medium' : 'hover:bg-muted',
                        )}
                    >
                        <span className="truncate">{role.name}</span>
                        <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                            {role.staffCount} {role.staffCount === 1 ? 'person' : 'people'}
                        </span>
                    </button>
                ))}
            </nav>
        </Card>
    );
}

export default function TillRoles({ roles, selected, groups, canEdit }: RoleEditorProps) {
    const role = roles.find((r) => r.id === selected) ?? null;
    const [granted, setGranted] = useState<string[]>(role?.granted ?? []);
    const [saving, setSaving] = useState(false);
    const locked = !canEdit || Boolean(role?.isOwner);
    const dirty = useMemo(() => [...granted].sort().join('|') !== [...(role?.granted ?? [])].sort().join('|'), [granted, role]);

    const toggle = (key: string, on: boolean) => setGranted((keys) => (on ? [...keys, key] : keys.filter((k) => k !== key)));
    const save = () => {
        if (!role) {
            return;
        }
        router.put(
            route('app.staff.roles.update', role.id),
            { permissions: granted },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    };

    return (
        <AppLayout>
            <Head title="Till roles" />
            <PageHeader
                title="Staff"
                description="What each till role may do. Roles come from your tills; a change here reaches every till at its next sync."
                tabs={<StaffTabs active="roles" />}
            />

            {!canEdit && <ReadOnlyNotice what="Till roles" />}

            {role === null ? (
                <EmptyState
                    icon={ShieldCheck}
                    title="No till roles yet"
                    body="Your tills send their roles at their first sync. Connect a till, let it sync, then come back."
                    bordered
                />
            ) : (
                <div className="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <RoleList roles={roles} selected={role.id} />
                    <div className="grid content-start gap-6">
                        <SectionCard
                            title={role.name}
                            description={
                                role.isOwner
                                    ? 'The Owner role always has every permission. The tills enforce this too.'
                                    : `${granted.length} of ${groups.reduce((n, g) => n + g.permissions.length, 0)} permissions.`
                            }
                            actions={role.isSystem && <StatusPill tone="neutral">Built in</StatusPill>}
                        >
                            <div className="grid gap-6">
                                {groups.map((group) => (
                                    <fieldset key={group.label} className="grid gap-3">
                                        <legend className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                                            {group.label}
                                        </legend>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            {group.permissions.map((p) => (
                                                <CheckField
                                                    key={p.key}
                                                    id={`perm-${p.key}`}
                                                    label={p.label}
                                                    help={p.help ?? <span className="font-mono">{p.key}</span>}
                                                    checked={role.isOwner || granted.includes(p.key)}
                                                    onChange={(on) => toggle(p.key, on)}
                                                    disabled={locked}
                                                />
                                            ))}
                                        </div>
                                    </fieldset>
                                ))}
                                <p className="text-muted-foreground text-xs leading-5">
                                    The list shows the permissions your tills have sent. After a till update adds new ones, they appear here at its
                                    next sync.
                                </p>
                            </div>
                        </SectionCard>
                    </div>
                </div>
            )}

            {dirty && !locked && (
                <StickyFormBar message={`Unsaved changes to ${role?.name}.`}>
                    <Button variant="outline" onClick={() => setGranted(role?.granted ?? [])} disabled={saving}>
                        Discard
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        {saving && <LoaderCircle className="size-4 animate-spin" />}
                        Save permissions
                    </Button>
                </StickyFormBar>
            )}
        </AppLayout>
    );
}
