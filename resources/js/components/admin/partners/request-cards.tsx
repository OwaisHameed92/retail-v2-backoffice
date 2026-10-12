import { type PartnerActivityRow, type PartnerRequestDetailData, type PartnerWebhookRow } from '@/components/admin/partners/types';
import { formatDate, formatDateTimeShort } from '@/components/admin/tenants/format';
import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Timeline, type TimelineItem } from '@/components/shared/timeline';
import { formatMoney, taxName } from '@/lib/country';
import { postcodeLabel } from '@/lib/country-address';
import { BadgeCheck, Ban, CircleX, Inbox, PencilLine, RotateCcw, TriangleAlert } from 'lucide-react';

/** The seller and the deal as the partner sent them (sale price, signed on, plan, agreed setup fee, notes). */
export function DealCard({ request }: { request: PartnerRequestDetailData }) {
    const deal = request.payload;

    return (
        <SectionCard title="Deal" description={`From ${request.partner ?? 'the partner'}.`}>
            <DescriptionList
                layout="rows"
                items={[
                    { label: 'Deal id', value: request.externalId, mono: true },
                    { label: 'Seller', value: request.sellerName },
                    {
                        label: 'Seller email',
                        value: (
                            <a href={`mailto:${request.sellerEmail}`} className="text-primary break-all hover:underline">
                                {request.sellerEmail}
                            </a>
                        ),
                    },
                    { label: 'Sale price', value: deal.salePrice ? formatMoney(deal.salePrice) : null },
                    { label: 'Signed on', value: deal.signedOn ? formatDate(`${deal.signedOn}T12:00:00Z`) : null },
                    { label: 'Plan code', value: deal.plan, mono: true },
                    {
                        label: 'Setup fee per till',
                        value: deal.setupFeePerTill ? `${formatMoney(deal.setupFeePerTill)} + ${taxName()}` : 'The plan’s',
                    },
                    { label: `Charge ${taxName()}`, value: deal.vatApplies ? 'Yes' : 'No' },
                    { label: 'Received', value: formatDateTimeShort(request.createdAt) },
                    { label: 'Last changed', value: formatDateTimeShort(request.updatedAt) },
                ]}
            />
            {deal.notes && (
                <figure className="mt-4 border-t pt-4">
                    <figcaption className="text-muted-foreground mb-1.5 text-[13px]">Partner notes</figcaption>
                    <blockquote className="text-sm leading-6 break-words whitespace-pre-line">{deal.notes}</blockquote>
                </figure>
            )}
        </SectionCard>
    );
}

/** What the partner sent, read only (approved, rejected and cancelled requests have no form). */
export function SentCard({ request }: { request: PartnerRequestDetailData }) {
    const { business, owner, shops } = request.payload;

    return (
        <SectionCard title="What the partner sent">
            <div className="grid gap-6">
                <DescriptionList
                    items={[
                        { label: 'Business', value: business.name },
                        { label: 'Legal name', value: business.legalName },
                        { label: 'Phone', value: business.phone },
                        { label: 'Email', value: business.email },
                        { label: 'Owner', value: owner.name },
                        { label: 'Owner’s email', value: owner.email },
                        { label: 'Owner’s phone', value: owner.phone },
                    ]}
                    columns={3}
                />
                <ul className="grid gap-3 sm:grid-cols-2">
                    {shops.map((shop, index) => (
                        <li key={index} className="grid gap-1 rounded-lg border p-3 text-sm">
                            <span className="font-medium">{shop.name}</span>
                            <span className="text-muted-foreground">
                                {[shop.address, shop.town, shop.postcode].filter(Boolean).join(', ') ||
                                    `No address or ${postcodeLabel().toLowerCase()}`}
                            </span>
                            <span className="tabular-nums">
                                {shop.tills} {shop.tills === 1 ? 'till' : 'tills'}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </SectionCard>
    );
}

/** The webhooks sent to the partner about this request, with delivery state. */
export function WebhooksCard({ webhooks }: { webhooks: PartnerWebhookRow[] }) {
    return (
        <SectionCard title="Partner updates" description="Signed webhooks, retried for 24 hours.">
            {webhooks.length === 0 ? (
                <p className="text-muted-foreground text-sm">Nothing sent yet. The partner hears when the request is approved or rejected.</p>
            ) : (
                <ul className="grid gap-3">
                    {webhooks.map((hook) => (
                        <li key={hook.id} className="flex items-start justify-between gap-3 text-sm">
                            <span className="grid min-w-0 gap-0.5">
                                <span className="font-medium">{hook.label}</span>
                                <span className="text-muted-foreground text-xs">
                                    {hook.deliveredAt
                                        ? `Delivered ${formatDateTimeShort(hook.deliveredAt)}`
                                        : hook.failedAt
                                          ? `Gave up ${formatDateTimeShort(hook.failedAt)}${hook.lastError ? ` · ${hook.lastError}` : ''}`
                                          : `${hook.attempts} ${hook.attempts === 1 ? 'try' : 'tries'}${hook.nextAttemptAt ? ` · next ${formatDateTimeShort(hook.nextAttemptAt)}` : ''}${hook.lastError ? ` · ${hook.lastError}` : ''}`}
                                </span>
                            </span>
                            <StatusBadge
                                status={hook.deliveredAt ? 'delivered' : hook.failedAt ? 'failed' : 'pending'}
                                label={hook.deliveredAt ? 'Delivered' : hook.failedAt ? 'Failed' : 'Retrying'}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

const activityText: Record<string, { title: string; icon: TimelineItem['icon']; tone: TimelineItem['tone'] }> = {
    'partner_request.received': { title: 'Received from the partner', icon: Inbox, tone: 'primary' },
    'partner_request.updated': { title: 'The partner changed the request', icon: PencilLine, tone: 'neutral' },
    'partner_request.reopened': { title: 'Sent again after the rejection', icon: RotateCcw, tone: 'primary' },
    'partner_request.approved': { title: 'Approved: business set up', icon: BadgeCheck, tone: 'success' },
    'partner_request.rejected': { title: 'Rejected', icon: CircleX, tone: 'danger' },
    'partner_request.cancelled': { title: 'Cancelled by the partner', icon: Ban, tone: 'neutral' },
    'partner_request.partner_cancelled': { title: 'The partner cancelled the deal after approval', icon: TriangleAlert, tone: 'warning' },
    'partner_request.business_cancelled': { title: 'The business was cancelled', icon: Ban, tone: 'neutral' },
};

export function ActivityCard({ activity }: { activity: PartnerActivityRow[] }) {
    const items: TimelineItem[] = activity.map((entry) => {
        const text = activityText[entry.action] ?? { title: entry.action, icon: PencilLine, tone: 'neutral' as const };

        return {
            id: entry.id,
            icon: text.icon,
            tone: text.tone,
            title: entry.actor ? `${text.title} by ${entry.actor}` : text.title,
            time: formatDateTimeShort(entry.at),
            body: entry.reason ? <p className="text-muted-foreground">{entry.reason}</p> : undefined,
        };
    });

    return (
        <SectionCard title="Activity">
            <Timeline items={items} />
        </SectionCard>
    );
}
