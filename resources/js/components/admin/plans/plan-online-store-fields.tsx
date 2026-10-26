import { CheckboxRow, Field, MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { type PlanOnlineStoreSettings } from '@/components/admin/plans/types';
import { formatMoney, taxName } from '@/lib/country';

interface PlanOnlineStoreFieldsProps {
    settings: PlanOnlineStoreSettings;
    /** The plan has a monthly or yearly fee: only those plans can include the online store. */
    recurs: boolean;
    included: boolean;
    price: string;
    onIncludedChange: (included: boolean) => void;
    onPriceChange: (price: string) => void;
    /** OS-13: online store only (no tills): the store fee is the plan's whole monthly fee. */
    onlineOnly: boolean;
    onOnlineOnlyChange: (onlineOnly: boolean) => void;
    errors: Partial<Record<string, string>>;
}

/**
 * OS-7 (owner 2026-10-24): the plan form's online store block, under the handheld one. A business put on the plan gets
 * the online store add-on: its own "Online store" line on the same monthly invoice, once per business. Only monthly or
 * yearly plans can include it. Not rendered where the country has no online stores.
 */
export function PlanOnlineStoreFields({
    settings,
    recurs,
    included,
    price,
    onIncludedChange,
    onPriceChange,
    onlineOnly,
    onOnlineOnlyChange,
    errors,
}: PlanOnlineStoreFieldsProps) {
    const defaultLabel = formatMoney(settings.defaultPrice);

    return (
        <div className="grid gap-3">
            <CheckboxRow
                id="includes_online_store"
                checked={recurs && included}
                disabled={!recurs || onlineOnly}
                onChange={onIncludedChange}
                label="Online store included"
                help={
                    recurs
                        ? `A business on this plan gets the click & collect online store. Billed as its own “Online store” line on the same monthly invoice, once per business whatever its branches and tills. Portal only: not sent to the till.`
                        : 'Only monthly or yearly plans can include the online store: it is billed on the monthly invoice. Choose a plan type with a monthly fee first.'
                }
                error={errors.includes_online_store}
            />
            {recurs && included && (
                <CheckboxRow
                    id="online_store_only"
                    checked={onlineOnly}
                    onChange={onOnlineOnlyChange}
                    label="Online store only (no tills)"
                    help="For a business with no till yet: it gets only the online store and sells the products made in the portal, with its own stock. No till prices, features or licence keys: the online store fee below is the plan’s whole monthly fee, once per business. It can move to a plan with tills later (Change plan)."
                    error={errors.online_store_only}
                />
            )}
            {recurs && included && (
                <Field
                    id="online_store_monthly_price"
                    label={onlineOnly ? `Monthly fee (before ${taxName()})` : `Online store fee per month (before ${taxName()})`}
                    error={errors.online_store_monthly_price}
                    help={
                        onlineOnly
                            ? `The online store fee, once per business, collected by Direct Debit. We suggest ${formatMoney('30')}. Blank = the default, ${defaultLabel}.`
                            : `Blank = the default, ${defaultLabel}. 0 for no charge. A business can still have its own price on its Billing tab.`
                    }
                    className="max-w-xs"
                >
                    <MoneyInput
                        id="online_store_monthly_price"
                        placeholder={settings.defaultPrice}
                        value={price}
                        invalid={!!errors.online_store_monthly_price}
                        onChange={(event) => onPriceChange(event.target.value)}
                    />
                </Field>
            )}
            {recurs && included && !settings.serverOn && (
                <p className="text-warning-foreground text-[13px]">
                    Online stores are switched off on this server (ONLINE_STORE). Businesses cannot be put on this plan until they are switched on.
                </p>
            )}
        </div>
    );
}
