/** Props of the public salon pages (W3a, app/Domain/Salon/Queries/*View.php). */

export interface SalonShop {
    name: string;
    phone: string;
    urls: { bookingUrl: string; queueJoinUrl: string; manageUrl: string };
}

export interface StylistPrice {
    userId: string;
    price: string;
    durationMin: number;
}

export interface ServiceOption {
    productId: string;
    label: string;
    price: string;
    durationMin: number;
    byStylist: StylistPrice[];
}

export interface AddOn {
    productId: string;
    name: string;
    price: string;
    extraMin: number;
}

export interface Service {
    productId: string;
    name: string;
    description: string;
    categoryName: string;
    clientGroup: string;
    priceIsFrom: boolean;
    needsPatchTest: boolean;
    depositAmount: string;
    options: ServiceOption[];
    addOns: AddOn[];
}

export interface Stylist {
    userId: string;
    firstName: string;
    levelName?: string;
    group: string;
}

export interface BookingRules {
    autoAccept: boolean;
    askPatchTest: boolean;
    questions: string[];
    terms: string;
    depositNote: string;
    minNoticeMin: number;
    holdMin: number;
}

export type ShopStatus = 'open' | 'offline' | 'closed';

export interface FreeDay {
    date: string;
    label: string;
    times: string[];
}

/** A chosen option (with the service it belongs to) in the client's basket. */
export interface BasketLine {
    service: Service;
    option: ServiceOption;
}
