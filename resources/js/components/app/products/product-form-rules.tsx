import { FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { CheckRow, MoneyInput, NumberField, OptionSelect } from './fields';
import { type SectionProps } from './types';
import { ukOnly } from '@/lib/country-text';

/** Age checks, sale limits and the legal flags the till and reports use. */
export function RulesSection({ data, setData, errors, options }: SectionProps) {
    return (
        <FormSection title="Age checks and rules" description="The till asks for ID and applies limits from these.">
            <FormGrid>
                <FormField id="age_rule" label="Age check" error={errors.age_rule}>
                    <OptionSelect id="age_rule" value={data.age_rule} options={options.ageRules} onChange={(v) => setData('age_rule', v)} />
                </FormField>
                <FormField id="max_qty_per_sale" label="Most per sale" optional help="Empty for no limit." error={errors.max_qty_per_sale}>
                    <NumberField id="max_qty_per_sale" inputMode="numeric" value={data.max_qty_per_sale} invalid={!!errors.max_qty_per_sale} onChange={(e) => setData('max_qty_per_sale', e.target.value)} />
                </FormField>
            </FormGrid>
            {data.max_qty_per_sale !== '' && (
                <FormField id="max_qty_reason" label="Reason shown to the cashier" optional error={errors.max_qty_reason}>
                    <Input id="max_qty_reason" maxLength={120} placeholder="e.g. Paracetamol: 2 packs per customer" value={data.max_qty_reason} onChange={(e) => setData('max_qty_reason', e.target.value)} />
                </FormField>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <CheckRow id="is_alcohol" checked={data.is_alcohol} onChange={(v) => setData('is_alcohol', v)} label="Alcohol" help="Licensing hours and alcohol duty reports.">
                    <FormGrid>
                        <FormField id="abv_percent" label="Strength (ABV)" optional error={errors.abv_percent}>
                            <NumberField id="abv_percent" suffix="%" value={data.abv_percent} invalid={!!errors.abv_percent} onChange={(e) => setData('abv_percent', e.target.value)} />
                        </FormField>
                        <FormField id="volume_ml" label="Volume" optional error={errors.volume_ml}>
                            <NumberField id="volume_ml" suffix="ml" value={data.volume_ml} invalid={!!errors.volume_ml} onChange={(e) => setData('volume_ml', e.target.value)} />
                        </FormField>
                    </FormGrid>
                </CheckRow>
                <CheckRow id="is_deposit_item" checked={data.is_deposit_item} onChange={(v) => setData('is_deposit_item', v)} label="Deposit return item" help="Adds the deposit to each sale.">
                    <FormField id="deposit_amount" label="Deposit" error={errors.deposit_amount}>
                        <MoneyInput id="deposit_amount" value={data.deposit_amount} invalid={!!errors.deposit_amount} onChange={(e) => setData('deposit_amount', e.target.value)} />
                    </FormField>
                </CheckRow>
                <CheckRow id="is_tobacco" checked={data.is_tobacco} onChange={(v) => setData('is_tobacco', v)} label="Tobacco" help="Kept out of promotions and discounts." />
                <CheckRow id="vape_duty_applies" checked={data.vape_duty_applies} onChange={(v) => setData('vape_duty_applies', v)} label="Vaping duty applies" help="Counted in vaping duty returns." />
                <CheckRow id="is_lottery" checked={data.is_lottery} onChange={(v) => setData('is_lottery', v)} label="Lottery" help="Sold as a lottery item." />
                <CheckRow id="is_knife" checked={data.is_knife} onChange={(v) => setData('is_knife', v)} label="Knife or blade" help={ukOnly('Challenge 25 and the refusals log.', 'Age check and the refusals log.')} />
                <CheckRow id="is_hfss" checked={data.is_hfss} onChange={(v) => setData('is_hfss', v)} label="High fat, sugar or salt" help="Kept out of HFSS-restricted promotions." />
                <CheckRow id="is_banned" checked={data.is_banned} onChange={(v) => setData('is_banned', v)} label="Do not sell" help="The till refuses to sell it." />
            </div>
            <FormGrid>
                <FormField id="commodity_code" label="Commodity code" optional help="For imports and exports." error={errors.commodity_code}>
                    <Input id="commodity_code" maxLength={20} value={data.commodity_code} onChange={(e) => setData('commodity_code', e.target.value)} />
                </FormField>
                <FormField id="net_mass_kg" label="Net weight" optional error={errors.net_mass_kg}>
                    <NumberField id="net_mass_kg" suffix="kg" value={data.net_mass_kg} invalid={!!errors.net_mass_kg} onChange={(e) => setData('net_mass_kg', e.target.value)} />
                </FormField>
            </FormGrid>
        </FormSection>
    );
}

/** The product's button on the till screen, and whether it is on sale. */
export function TillSection({ data, setData, errors }: SectionProps) {
    return (
        <FormSection title="Till button" description="How the product looks on the till's touch screen.">
            <FormGrid columns={3}>
                <FormField id="tile_colour_hex" label="Colour" error={errors.tile_colour_hex}>
                    <div className="flex items-center gap-2">
                        <input
                            type="color"
                            aria-label="Pick a colour"
                            className="border-input h-9 w-11 shrink-0 cursor-pointer rounded-md border bg-transparent p-1"
                            value={/^#[0-9a-f]{6}$/i.test(data.tile_colour_hex) ? data.tile_colour_hex : '#1f6feb'}
                            onChange={(e) => setData('tile_colour_hex', e.target.value.toUpperCase())}
                        />
                        <Input id="tile_colour_hex" maxLength={7} className="font-mono uppercase" value={data.tile_colour_hex} aria-invalid={!!errors.tile_colour_hex} onChange={(e) => setData('tile_colour_hex', e.target.value)} />
                    </div>
                </FormField>
                <FormField id="tile_emoji" label="Emoji" optional error={errors.tile_emoji}>
                    <Input id="tile_emoji" maxLength={16} value={data.tile_emoji} onChange={(e) => setData('tile_emoji', e.target.value)} />
                </FormField>
                <FormField id="tile_position" label="Position" help="Lower numbers come first." error={errors.tile_position}>
                    <NumberField id="tile_position" inputMode="numeric" value={data.tile_position} invalid={!!errors.tile_position} onChange={(e) => setData('tile_position', e.target.value)} />
                </FormField>
            </FormGrid>
            <CheckRow id="is_active" checked={data.is_active} onChange={(v) => setData('is_active', v)} label="On sale" help="Turn off to archive: the tills stop offering it, and its history stays." />
        </FormSection>
    );
}
