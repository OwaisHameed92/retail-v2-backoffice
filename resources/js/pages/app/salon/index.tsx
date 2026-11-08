import { BookingRequestsCard } from '@/components/app/salon/booking-requests-card';
import { FeedLinkDialog } from '@/components/app/salon/feed-link-dialog';
import { OnlineBookingStatusCard } from '@/components/app/salon/online-booking-status-card';
import { StylistFeedsCard, type FeedCommand } from '@/components/app/salon/stylist-feeds-card';
import { type FeedLinkReply, type SalonPageProps } from '@/components/app/salon/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { showToast } from '@/components/shared/toaster';
import AppLayout from '@/layouts/app-layout';
import { sendJson } from '@/lib/http';
import { Head, router } from '@inertiajs/react';
import { CalendarCheck2, CalendarDays, Inbox, Scissors, Users } from 'lucide-react';
import { useState } from 'react';

const reload = () => router.reload({ only: ['shops', 'stats'] });

export default function SalonIndex({ shops, stats, requests, requestFilters, requestStatuses, shopFilter, onlineBooking }: SalonPageProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [link, setLink] = useState<{ name: string; reply: FeedLinkReply } | null>(null);
    const [confirm, setConfirm] = useState<FeedCommand | null>(null);
    const many = shops.length > 1;

    const issue = async ({ shop, stylist }: FeedCommand) => {
        setBusy(`${shop.id}|${stylist.userId}`);
        const result = await sendJson<FeedLinkReply>('POST', route('app.salon.feeds.issue'), { shopId: shop.id, userId: stylist.userId });
        setBusy(null);

        if (!result.ok || !result.data) {
            showToast(result.message ?? 'The calendar feed could not be turned on. Try again.', 'error');

            return;
        }
        setLink({ name: stylist.name, reply: result.data });
        reload();
    };

    const revoke = async ({ shop, stylist }: FeedCommand) => {
        if (!stylist.feed) {
            return;
        }
        setBusy(`${shop.id}|${stylist.userId}`);
        const result = await sendJson('DELETE', route('app.salon.feeds.revoke', stylist.feed.id));
        setBusy(null);

        if (!result.ok) {
            showToast(result.message ?? 'The calendar feed could not be turned off. Try again.', 'error');

            return;
        }
        showToast(`${stylist.name}’s calendar feed is off. Their calendar stops updating.`, 'success');
        reload();
    };

    const onCommand = (command: FeedCommand) => (command.kind === 'enable' ? issue(command) : setConfirm(command));

    return (
        <AppLayout>
            <Head title="Salon" />

            <PageHeader
                title="Salon"
                icon={Scissors}
                description="Your stylists’ calendar feeds and the online booking requests your tills decide. Everything here comes from the shop’s till."
            />

            {shops.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={Scissors}
                        title={shopFilter ? `${shopFilter} is not a salon` : 'No open salon shops'}
                        body={
                            shopFilter
                                ? 'Choose a salon shop, or All branches, in the shop switcher at the top.'
                                : 'A shop shows here once its till is set up as Salon / Barber.'
                        }
                    />
                </SectionCard>
            ) : (
                <>
                    <StatGrid>
                        <StatCard
                            label="Appointments today"
                            value={stats.todayAppointments}
                            icon={CalendarDays}
                            tone="primary"
                            hint="Not cancelled"
                        />
                        <StatCard
                            label="Stylists"
                            value={stats.stylists}
                            icon={Users}
                            tone="neutral"
                            hint={many ? `In ${shops.length} shops` : undefined}
                        />
                        <StatCard label="Calendar feeds on" value={stats.feedsOn} icon={CalendarCheck2} tone="success" />
                        <StatCard
                            label="Requests waiting"
                            value={stats.pendingRequests}
                            icon={Inbox}
                            tone={stats.pendingRequests > 0 ? 'warning' : 'neutral'}
                            hint="For the till to answer"
                        />
                    </StatGrid>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:items-start">
                        <div className="grid gap-6">
                            {shops.map((shop) => (
                                <StylistFeedsCard key={shop.id} shop={shop} showShop={many} busy={busy} onCommand={onCommand} />
                            ))}
                        </div>
                        <OnlineBookingStatusCard status={onlineBooking} />
                    </div>

                    <BookingRequestsCard requests={requests} status={requestFilters.status} statuses={requestStatuses} showShop={many} />
                </>
            )}

            <FeedLinkDialog link={link} onClose={() => setLink(null)} />
            {confirm && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setConfirm(null)}
                    title={
                        confirm.kind === 'regenerate'
                            ? `Give ${confirm.stylist.name}’s feed a new address?`
                            : `Turn off ${confirm.stylist.name}’s calendar feed?`
                    }
                    description={
                        confirm.kind === 'regenerate'
                            ? 'The current address stops working at once. You get a new one to add to their phone calendar.'
                            : 'The address stops working at once and their phone calendar stops updating. Turning it on again gives a new address.'
                    }
                    confirmLabel={confirm.kind === 'regenerate' ? 'Make a new address' : 'Turn off'}
                    destructive
                    onConfirm={async () => {
                        const command = confirm;
                        setConfirm(null);
                        await (command.kind === 'regenerate' ? issue(command) : revoke(command));
                    }}
                />
            )}
        </AppLayout>
    );
}
