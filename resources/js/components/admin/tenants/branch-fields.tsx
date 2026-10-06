import { Field, Textarea } from '@/components/admin/tenants/field';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { keepsUkStyles, vatNumberLabel } from '@/lib/country';
import { postcodeInputProps, postcodeLabel, townHint, townNeededWithAddress } from '@/lib/country-address';
import { localPlaces } from '@/lib/country-text';

export type BranchFieldsData = {
    code: string;
    name: string;
    nation: Nation;
    address: string;
    phone: string;
    vat_number: string;
    area_m2: string;
    is_drs_return_point: boolean;
    licensed_hours_json: string;
    /** Module 1.11: the licence key's shop details (blank = the business's). */
    town: string;
    postcode: string;
    receipt_footer: string;
};

interface BranchFieldsProps {
    /** Field ids and error keys get this prefix ("branch_" in the create wizard). */
    prefix?: string;
    data: BranchFieldsData;
    setField: <K extends keyof BranchFieldsData>(key: K, value: BranchFieldsData[K]) => void;
    errors: Partial<Record<string, string>>;
    nations: Option<Nation>[];
    /** Show the licensed hours JSON field (edit dialog only). */
    showLicensedHours?: boolean;
}

/** Branch fields shared by the create wizard and the branch dialog. Rendered inside a two-column grid. */
export function BranchFields({ prefix = '', data, setField, errors, nations, showLicensedHours = false }: BranchFieldsProps) {
    const id = (key: string) => `${prefix}${key}`;
    const error = (key: string) => errors[`${prefix}${key}`];

    return (
        <>
            <Field id={id('name')} label="Branch name" hint={localPlaces('Usually the town or street, e.g. Leeds.')} error={error('name')}>
                <Input id={id('name')} required value={data.name} onChange={(e) => setField('name', e.target.value)} aria-invalid={!!error('name')} />
            </Field>
            <Field id={id('code')} label="Branch code" hint={localPlaces('2 to 5 letters, used in receipt numbers like LDS-01-000482.')} error={error('code')}>
                <Input
                    id={id('code')}
                    required
                    maxLength={5}
                    value={data.code}
                    onChange={(e) => setField('code', e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))}
                    className="font-mono uppercase"
                    aria-invalid={!!error('code')}
                    autoComplete="off"
                />
            </Field>
            {/* Pakistan plan P9: no nations off GB (the till's values are the UK's), so the field is hidden there. */}
            {nations.length > 0 && (
                <Field id={id('nation')} label="Nation" hint="Sets deposit return and licensing rules on the till." error={error('nation')}>
                    <Select value={data.nation} onValueChange={(value) => setField('nation', value as Nation)}>
                        <SelectTrigger id={id('nation')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {nations.map((nation) => (
                                <SelectItem key={nation.value} value={nation.value}>
                                    {nation.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}
            <Field id={id('phone')} label="Shop phone" optional error={error('phone')}>
                <Input
                    id={id('phone')}
                    type="tel"
                    value={data.phone}
                    onChange={(e) => setField('phone', e.target.value)}
                    aria-invalid={!!error('phone')}
                />
            </Field>
            <Field id={id('address')} label="Shop address" optional error={error('address')} className="sm:col-span-2">
                <Textarea id={id('address')} rows={2} value={data.address} onChange={(e) => setField('address', e.target.value)} />
            </Field>
            <Field id={id('town')} label="Town" optional={!townNeededWithAddress()} hint={townHint()} error={error('town')}>
                <Input id={id('town')} value={data.town} onChange={(e) => setField('town', e.target.value)} aria-invalid={!!error('town')} />
            </Field>
            <Field id={id('postcode')} label={postcodeLabel()} optional error={error('postcode')}>
                <Input
                    {...postcodeInputProps()}
                    id={id('postcode')}
                    value={data.postcode}
                    onChange={(e) => setField('postcode', e.target.value.toUpperCase())}
                    aria-invalid={!!error('postcode')}
                    autoComplete="off"
                    className="max-w-40 uppercase"
                />
            </Field>
            <Field
                id={id('vat_number')}
                label={keepsUkStyles() ? 'Branch VAT number' : `Branch ${vatNumberLabel()}`}
                optional
                hint="Only if different from the business."
                error={error('vat_number')}
            >
                <Input
                    id={id('vat_number')}
                    value={data.vat_number}
                    onChange={(e) => setField('vat_number', e.target.value.toUpperCase())}
                    aria-invalid={!!error('vat_number')}
                    autoComplete="off"
                />
            </Field>
            <Field id={id('area_m2')} label="Floor area (m²)" optional error={error('area_m2')}>
                <Input
                    id={id('area_m2')}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step="0.01"
                    value={data.area_m2}
                    onChange={(e) => setField('area_m2', e.target.value)}
                    aria-invalid={!!error('area_m2')}
                />
            </Field>
            <Field
                id={id('receipt_footer')}
                label="Receipt footer"
                optional
                hint="Only if different from the business’s."
                error={error('receipt_footer')}
                className="sm:col-span-2"
            >
                <Textarea
                    id={id('receipt_footer')}
                    rows={2}
                    maxLength={200}
                    value={data.receipt_footer}
                    onChange={(e) => setField('receipt_footer', e.target.value)}
                />
            </Field>
            <div className="flex items-start gap-3 sm:col-span-2">
                <Checkbox
                    id={id('is_drs_return_point')}
                    checked={data.is_drs_return_point}
                    onCheckedChange={(checked) => setField('is_drs_return_point', checked === true)}
                    className="mt-0.5"
                />
                <div className="grid gap-1">
                    <Label htmlFor={id('is_drs_return_point')}>Deposit return point</Label>
                    <p className="text-muted-foreground text-sm">The shop takes back drinks containers under the deposit return scheme.</p>
                </div>
            </div>
            {showLicensedHours && (
                <Field
                    id={id('licensed_hours_json')}
                    label="Licensed hours"
                    optional
                    hint="JSON as set on the till. Leave blank if the shop does not sell alcohol."
                    error={error('licensed_hours_json')}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id={id('licensed_hours_json')}
                        rows={3}
                        className="font-mono text-xs"
                        value={data.licensed_hours_json}
                        onChange={(e) => setField('licensed_hours_json', e.target.value)}
                        aria-invalid={!!error('licensed_hours_json')}
                    />
                </Field>
            )}
        </>
    );
}
