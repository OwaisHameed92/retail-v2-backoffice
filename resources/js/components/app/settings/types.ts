/** Module 4.9: props of `app/settings/index` (App\Domain\ShopSettings\Queries\ShopSettingsPage). */

export type SettingType = 'bool' | 'int' | 'money' | 'percent' | 'decimal' | 'text' | 'multiline';

export interface SettingDefinition {
    key: string;
    label: string;
    help: string;
    type: SettingType;
    min?: number;
    max?: number;
    unit?: string;
    /** The till's built-in default, only where the contract states it. */
    default?: string;
    /** One value for the whole business: not offered per shop. */
    everyShopOnly?: boolean;
}

export interface SettingSection {
    id: string;
    title: string;
    description: string;
    settings: SettingDefinition[];
}

export interface ShopOption {
    id: string;
    name: string;
    code: string;
}

export interface ShopSettingsProps {
    /** null = every shop (the business's settings). */
    shop: ShopOption | null;
    shops: ShopOption[];
    canEveryShop: boolean;
    sections: SettingSection[];
    /** The values set at this level, as the till stores them. */
    values: Record<string, string>;
    /** One shop only: what the shop gets without its own value. */
    inherited: Record<string, { value: string | null; from: 'everyShop' | 'default' }>;
    /** Every shop only: the shops with their own value, per key. */
    overrides: Record<string, string[]>;
}

/** A stored value as people read it: "On", "£2.50", "10%", "15 minutes". */
export function displayValue(definition: SettingDefinition, value: string | null | undefined): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    switch (definition.type) {
        case 'bool':
            return value === 'true' ? 'On' : 'Off';
        case 'money':
            return `£${value}`;
        case 'percent':
            return `${value}%`;
        case 'multiline':
            return value.split('\n')[0] + (value.includes('\n') ? ' …' : '');
        default:
            return definition.unit && definition.unit !== '£' ? `${value} ${definition.unit}` : value;
    }
}
