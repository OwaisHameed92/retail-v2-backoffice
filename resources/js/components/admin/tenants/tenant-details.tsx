import { formatDate } from '@/components/admin/tenants/format';
import { type Tenant } from '@/components/admin/tenants/types';
import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CircleAlert, CirclePause } from 'lucide-react';

/** Status notice (suspended/cancelled) and the business details card. */
export function TenantDetails({ tenant }: { tenant: Tenant }) {
    return (
        <>
            {tenant.status === 'suspended' && (
                <Alert variant="destructive" role="status">
                    <CirclePause className="size-4" />
                    <AlertTitle>Suspended on {formatDate(tenant.suspendedAt)}</AlertTitle>
                    <AlertDescription>{tenant.suspensionReason}</AlertDescription>
                </Alert>
            )}
            {tenant.status === 'cancelled' && (
                <Alert role="status">
                    <CircleAlert className="size-4" />
                    <AlertTitle>Cancelled on {formatDate(tenant.cancelledAt)}</AlertTitle>
                    <AlertDescription className="text-muted-foreground">{tenant.cancellationReason}</AlertDescription>
                </Alert>
            )}

            <SectionCard title="Business details">
                <DescriptionList
                    items={[
                        { label: 'Main contact', value: tenant.contactName },
                        {
                            label: 'Email',
                            value: tenant.email && (
                                <a href={`mailto:${tenant.email}`} className="text-primary font-medium hover:underline">
                                    {tenant.email}
                                </a>
                            ),
                        },
                        { label: 'Phone', value: tenant.phone },
                        { label: 'VAT number', value: tenant.vatNumber },
                        { label: 'Company number', value: tenant.companyNumber },
                        { label: 'Registered address', value: tenant.address && <span className="whitespace-pre-line">{tenant.address}</span> },
                        { label: 'Active since', value: tenant.activatedAt ? formatDate(tenant.activatedAt) : null },
                        { label: 'Tenant id', value: tenant.id, mono: true },
                        ...(tenant.notes
                            ? [
                                  {
                                      label: 'Internal notes',
                                      value: <span className="font-normal whitespace-pre-line">{tenant.notes}</span>,
                                      wide: true,
                                  },
                              ]
                            : []),
                    ]}
                />
            </SectionCard>
        </>
    );
}
