import { PartnerCancelledPill, PartnerRequestStatusBadge } from '@/components/admin/partners/partner-request-status';
import { ActivityCard, DealCard, SentCard, WebhooksCard } from '@/components/admin/partners/request-cards';
import { ReviewForm } from '@/components/admin/partners/review-form';
import {
    type PartnerActivityRow,
    type PartnerRequestDetailData,
    type PartnerWebhookRow,
    type ReviewFormValues,
    type ReviewOptions,
} from '@/components/admin/partners/types';
import { formatDate } from '@/components/admin/tenants/format';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BadgeCheck, Ban, CircleX, Handshake, TriangleAlert } from 'lucide-react';

interface PartnerRequestShowProps {
    request: PartnerRequestDetailData;
    webhooks: PartnerWebhookRow[];
    activity: PartnerActivityRow[];
    form: ReviewFormValues | null;
    options: ReviewOptions;
    can: { manage: boolean; viewTenant: boolean; billing: boolean };
}

function TenantLink({ request, label }: { request: PartnerRequestDetailData; label: string }) {
    if (!request.company || request.company.deleted) {
        return null;
    }

    return (
        <Button asChild size="sm" variant="outline" className="bg-card shrink-0">
            <Link href={route('admin.tenants.show', request.company.id)}>
                {label}
                <ArrowRight />
            </Link>
        </Button>
    );
}

function StatusAlert({ request }: { request: PartnerRequestDetailData }) {
    const partner = request.partner ?? 'The partner';

    if (request.status === 'approved' && request.partnerCancelled) {
        return (
            <Alert variant="warning">
                <TriangleAlert className="size-4" />
                <AlertTitle>Partner cancelled the deal {request.partnerCancelledAt && `on ${formatDate(request.partnerCancelledAt)}`}</AlertTitle>
                <AlertDescription>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {partner} says: “{request.cancelledReason}”. The business was not changed: it may already be trading. Check with the
                            owner, then suspend or cancel it from the business page if needed.
                        </span>
                        <TenantLink request={request} label="Open business" />
                    </div>
                </AlertDescription>
            </Alert>
        );
    }

    if (request.status === 'approved') {
        return (
            <Alert variant="success">
                <BadgeCheck className="size-4" />
                <AlertTitle>Approved {request.approvedAt && `on ${formatDate(request.approvedAt)}`}</AlertTitle>
                <AlertDescription>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {request.company ? `${request.company.name} is set up` : 'The business made from this request no longer exists'}
                            {request.approvedBy && `, approved by ${request.approvedBy}`}.
                        </span>
                        <TenantLink request={request} label="Open business" />
                    </div>
                </AlertDescription>
            </Alert>
        );
    }

    if (request.status === 'rejected') {
        return (
            <Alert>
                <CircleX className="size-4" />
                <AlertTitle>
                    Rejected {request.rejectedAt && `on ${formatDate(request.rejectedAt)}`}
                    {request.rejectedBy && ` by ${request.rejectedBy}`}
                </AlertTitle>
                <AlertDescription>
                    {request.rejectedReason} {partner} can send the deal again; it then comes back here for review.
                </AlertDescription>
            </Alert>
        );
    }

    if (request.status === 'cancelled') {
        return (
            <Alert>
                <Ban className="size-4" />
                <AlertTitle>Cancelled {request.cancelledAt && `on ${formatDate(request.cancelledAt)}`}</AlertTitle>
                <AlertDescription>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>{request.cancelledReason ?? 'No reason given.'}</span>
                        <TenantLink request={request} label="Open business" />
                    </div>
                </AlertDescription>
            </Alert>
        );
    }

    return null;
}

/**
 * Admin → Partner requests → one request: the deal and the seller on the side; while pending, the new-business form
 * pre-filled from the partner's body to check and edit, then Approve or Reject.
 */
export default function PartnerRequestShow({ request, webhooks, activity, form, options, can }: PartnerRequestShowProps) {
    const partner = request.partner ?? 'the partner';

    return (
        <AdminLayout>
            <Head title={request.businessName} />

            <PageHeader
                title={request.businessName}
                status={
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        <PartnerRequestStatusBadge status={request.status} withHelp />
                        {request.partnerCancelled && <PartnerCancelledPill />}
                    </span>
                }
                back={{ href: route('admin.partner-requests.index'), label: 'Partner requests' }}
                media={<InitialsAvatar name={request.businessName} shape="square" size="lg" icon={Handshake} />}
                description={`${request.partner ?? 'Partner'} deal ${request.externalId} · sold by ${request.sellerName} · received ${formatDate(request.createdAt)}`}
            />

            <StatusAlert request={request} />

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                <div className="grid gap-6 lg:col-start-3 lg:row-start-1">
                    <DealCard request={request} />
                    <WebhooksCard webhooks={webhooks} />
                    <ActivityCard activity={activity} />
                </div>

                <div className="grid min-w-0 gap-6 lg:col-span-2 lg:col-start-1 lg:row-start-1">
                    {form && can.manage ? (
                        <ReviewForm requestId={request.id} businessName={request.businessName} partner={partner} initial={form} options={options} />
                    ) : (
                        <SentCard request={request} />
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
