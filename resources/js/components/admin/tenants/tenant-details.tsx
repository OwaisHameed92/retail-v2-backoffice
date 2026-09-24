import { formatDate } from '@/components/admin/tenants/format';
import { type Tenant } from '@/components/admin/tenants/types';
import { Card } from '@/components/ui/card';
import { CircleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm break-words">{children || <span className="text-muted-foreground">Not set</span>}</dd>
        </div>
    );
}

/** Status notice (suspended/cancelled) and the business details card. */
export function TenantDetails({ tenant }: { tenant: Tenant }) {
    return (
        <>
            {tenant.status === 'suspended' && (
                <div role="status" className="border-destructive/20 bg-danger-soft flex items-start gap-3 rounded-lg border p-4 text-sm">
                    <CircleAlert className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden />
                    <div>
                        <p className="font-medium">Suspended on {formatDate(tenant.suspendedAt)}</p>
                        <p className="text-muted-foreground">{tenant.suspensionReason}</p>
                    </div>
                </div>
            )}
            {tenant.status === 'cancelled' && (
                <div role="status" className="bg-muted flex items-start gap-3 rounded-lg border p-4 text-sm">
                    <CircleAlert className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                    <div>
                        <p className="font-medium">Cancelled on {formatDate(tenant.cancelledAt)}</p>
                        <p className="text-muted-foreground">{tenant.cancellationReason}</p>
                    </div>
                </div>
            )}

            <Card className="p-4 sm:p-5">
                <h2 className="mb-4 text-base font-semibold">Business details</h2>
                <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Item label="Main contact">{tenant.contactName}</Item>
                    <Item label="Email">
                        {tenant.email && (
                            <a href={`mailto:${tenant.email}`} className="text-primary hover:underline">
                                {tenant.email}
                            </a>
                        )}
                    </Item>
                    <Item label="Phone">{tenant.phone}</Item>
                    <Item label="VAT number">{tenant.vatNumber}</Item>
                    <Item label="Company number">{tenant.companyNumber}</Item>
                    <Item label="Registered address">{tenant.address && <span className="whitespace-pre-line">{tenant.address}</span>}</Item>
                    <Item label="Active since">{tenant.activatedAt ? formatDate(tenant.activatedAt) : null}</Item>
                    <Item label="Tenant id">
                        <span className="font-mono text-xs">{tenant.id}</span>
                    </Item>
                    {tenant.notes && (
                        <div className="space-y-1 sm:col-span-2 lg:col-span-4">
                            <dt className="text-muted-foreground text-sm">Internal notes</dt>
                            <dd className="text-sm whitespace-pre-line">{tenant.notes}</dd>
                        </div>
                    )}
                </dl>
            </Card>
        </>
    );
}
