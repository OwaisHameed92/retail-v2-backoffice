import { shopDate, type ShopRequestRow } from '@/components/app/shops/types';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { ago, shopDateTime } from '@/components/till-health/format';
import { MessageSquareText, Monitor, Store } from 'lucide-react';

/** What was asked, in one line: "2 more tills for Leeds (LDS)" / "A new shop, Harrogate, with 1 till". */
export function requestText(request: ShopRequestRow): string {
    const tills = `${request.tills} ${request.tills === 1 ? 'till' : 'tills'}`;
    if (request.kind === 'newShop') {
        return `A new shop${request.newShopName ? `, ${request.newShopName},` : ''} with ${tills}`;
    }

    return `${request.tills} more ${request.tills === 1 ? 'till' : 'tills'}${request.shop ? ` for ${request.shop.name} (${request.shop.code})` : ''}`;
}

/** "Your requests" (module 4.7): what the business asked Switch & Save for, open first. Hidden when there are none. */
export function RequestsCard({ requests }: { requests: ShopRequestRow[] }) {
    if (requests.length === 0) {
        return null;
    }

    return (
        <SectionCard title="Your requests" description="What you asked us for. We mark a request done once the tills or shop are set up." flush>
            <ul className="divide-y">
                {requests.map((request) => {
                    const Icon = request.kind === 'newShop' ? Store : Monitor;

                    return (
                        <li key={request.id} className="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:px-5">
                            <div className="flex min-w-0 gap-3">
                                <span className="bg-primary-soft text-primary mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full">
                                    <Icon className="size-4" aria-hidden />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">{requestText(request)}</p>
                                    <p className="text-muted-foreground text-xs" title={shopDateTime(request.sentAt)}>
                                        Sent {shopDate(request.sentAt)}
                                        {request.requestedBy && ` by ${request.requestedBy}`}
                                        {request.count > 1 && ` · asked ${request.count} times, last ${ago(request.lastAskedAt)}`}
                                    </p>
                                    {request.message && (
                                        <p className="text-muted-foreground mt-1.5 flex gap-1.5 text-xs">
                                            <MessageSquareText className="mt-px size-3.5 shrink-0" aria-hidden />
                                            <span className="line-clamp-2">{request.message}</span>
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="shrink-0 pl-11 sm:pl-0">
                                {request.done ? (
                                    <StatusPill tone="success">Done {shopDate(request.doneAt)}</StatusPill>
                                ) : (
                                    <StatusPill tone="info">With our team</StatusPill>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
        </SectionCard>
    );
}
