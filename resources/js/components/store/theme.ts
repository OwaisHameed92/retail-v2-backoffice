import { type StoreTemplate } from './types';
import { useStore } from './use-store';

/**
 * The store's template (OS-5). Pages and components are shared; the template swaps the tokens in store.css
 * (fonts, ink, paper, highlight), the home page's hero, department tiles, featured products and steps, and the
 * words below. Anything unknown is Fresh Market.
 */
export function templateOf(value: string | null | undefined): StoreTemplate {
    return value === 'butcher' ? 'butcher' : 'freshMarket';
}

export function useTemplate(): StoreTemplate {
    return templateOf(useStore()?.template);
}

interface TemplateCopy {
    defaultHeadline: string;
    departmentsTitle: string;
    allProducts: string;
    featuredTitle: string;
    featuredSub: string;
    startShopping: string;
    aboutLead: string;
    /** The second "about" fact: the big word and its title. */
    priceFact: { big: string | null; title: string; text: string };
}

export const COPY: Record<StoreTemplate, TemplateCopy> = {
    freshMarket: {
        defaultHeadline: 'Your local shop, ready when you are.',
        departmentsTitle: 'Shop by aisle',
        allProducts: 'All products',
        featuredTitle: 'On the shelves now',
        featuredSub: "Prices and stock come live from the shop's tills",
        startShopping: 'Start shopping',
        aboutLead:
            'Your local shop, now online. What you see here comes straight from our tills, so the prices and stock are what you would find in the shop today.',
        priceFact: { big: null, title: 'Price difference', text: 'The same price as on the shelf, from our tills.' },
    },
    butcher: {
        defaultHeadline: 'Cut to order.',
        departmentsTitle: 'Shop by meat',
        allProducts: 'All cuts',
        featuredTitle: "Butcher's picks",
        featuredSub: 'Pick the weight. Priced by the kilo, so you only pay for what you get.',
        startShopping: 'Shop all meat',
        aboutLead:
            'Your local butcher, now online. Prices and stock come straight from our tills. Weighed cuts show an estimated price: we weigh your order at the counter and you pay the real weight.',
        priceFact: { big: 'Real', title: 'Weight, real price', text: 'Weighed at the counter. You pay for what you get.' },
    },
};

/** Soft placeholder tints per template, for products and departments without a photo. */
export const TINTS: Record<StoreTemplate, string[]> = {
    freshMarket: ['#E7EFD9', '#F3E6D6', '#E2ECEA', '#F1E3E3', '#E9E5F2', '#F4ECCF', '#DDEBE2', '#EFE7DC'],
    butcher: ['#F3E6D3', '#EFE1D9', '#F1E3E3', '#EDE4D6', '#F4ECDD', '#E9DCD2', '#F2E8E0', '#EBDFCF'],
};
