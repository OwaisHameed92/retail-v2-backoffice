import { type Paginated } from '@/components/shared/data-table';

/** A stylist's calendar feed state (never the address: that is shown once, from the "on" reply). */
export interface SalonFeed {
    id: string;
    on: boolean;
    last4: string | null;
    issuedAt: string | null;
    lastFetchedAt: string | null;
}

export interface SalonStylist {
    userId: string;
    name: string;
    /** False when the till no longer lists them in the diary but their feed is still on. */
    onTill: boolean;
    bookable: boolean;
    onlineBookable: boolean;
    chairRenter: boolean;
    feed: SalonFeed | null;
}

export interface SalonShop {
    id: string;
    name: string;
    code: string;
    todayAppointments: number;
    stylists: SalonStylist[];
}

export type BookingStatus = 'pending' | 'accepted' | 'declined' | 'expired' | 'cancelled';

export type BookingKind = 'new' | 'reschedule' | 'cancel' | 'preferences';

export interface BookingRequestRow {
    id: string;
    reference: string;
    kind: BookingKind | null;
    status: BookingStatus;
    requestedStartsAt: string | null;
    clientFirstName: string;
    shopName: string | null;
    createdAt: string | null;
    decidedAt: string | null;
}

/** W3a fills this from the till's booking page and heartbeat; null until then. */
export interface OnlineBookingStatus {
    accepting: boolean;
    lastHeardAt: string | null;
    bookingUrl: string | null;
    queueJoinUrl: string | null;
    /** W3a: the shop the card is about, and open / offline ("request only") / closed. */
    shopName?: string;
    state?: 'open' | 'offline' | 'closed';
}

export interface SalonPageProps {
    shops: SalonShop[];
    stats: { stylists: number; feedsOn: number; todayAppointments: number; pendingRequests: number };
    requests: Paginated<BookingRequestRow>;
    requestFilters: { status: BookingStatus | null };
    requestStatuses: BookingStatus[];
    /** The top-bar shop when it is not a salon (the page then explains why it is empty). */
    shopFilter: string | null;
    onlineBooking: OnlineBookingStatus | null;
}

/** The one-time reply after a feed is turned on or given a new address. */
export interface FeedLinkReply {
    feed: SalonFeed;
    regenerated: boolean;
    url: string;
    webcalUrl: string;
    qrSvg: string;
}

export const BOOKING_STATUS_LABELS: Record<BookingStatus, string> = {
    pending: 'Waiting for the till',
    accepted: 'Accepted',
    declined: 'Declined',
    expired: 'Expired',
    cancelled: 'Withdrawn',
};

export const BOOKING_STATUS_TONES: Record<BookingStatus, 'warning' | 'success' | 'danger' | 'neutral'> = {
    pending: 'warning',
    accepted: 'success',
    declined: 'danger',
    expired: 'neutral',
    cancelled: 'neutral',
};

export const BOOKING_KIND_LABELS: Record<BookingKind, string> = {
    new: 'New booking',
    reschedule: 'Move',
    cancel: 'Cancel',
    preferences: 'Marketing choices',
};
