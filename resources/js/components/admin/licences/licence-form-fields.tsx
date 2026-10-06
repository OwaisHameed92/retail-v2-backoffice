import { type LengthUnit, type LicenceKind, type LicenceOptions } from '@/components/admin/licences/types';
import { FormField } from '@/components/shared/form-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { zonedDateFormat } from '@/lib/country';
import { cn } from '@/lib/utils';

/** A branch's licence settings as form values (module 1.11). Field names match LicenceFormRules. */
export type LicenceFormValues = {
    max_registers: number;
    kind: LicenceKind;
    length: string;
    length_unit: LengthUnit | '';
    valid_from: string;
    features: string[];
};

interface LicenceFormFieldsProps {
    values: LicenceFormValues;
    setValue: <K extends keyof LicenceFormValues>(key: K, value: LicenceFormValues[K]) => void;
    errors: Partial<Record<string, string>>;
    options: LicenceOptions;
    /** Show "Tills allowed" (off where each shop has its own, e.g. lead approval). */
    showTills?: boolean;
    /** Show the start date (off for a brand-new customer: keys start on activation). */
    showStart?: boolean;
    /** Keys in use in the branch: tills allowed cannot go below it. */
    tillsInUse?: number;
    /** The plan's trial length, for the "blank length" hint. */
    trialDays?: number;
    idPrefix?: string;
}

/**
 * The owner's "customer form" for a branch's keys: tills allowed, trial or full, length (from a start date or
 * each till's first activation) and features with the till's names ("portal only" when the till has none).
 */
export function LicenceFormFields({
    values,
    setValue,
    errors,
    options,
    showTills = true,
    showStart = true,
    tillsInUse,
    trialDays,
    idPrefix = 'licence',
}: LicenceFormFieldsProps) {
    const id = (name: string) => `${idPrefix}-${name}`;
    const selected = new Set(values.features);
    const toggle = (value: string, on: boolean) =>
        setValue(
            'features',
            options.features.map((feature) => feature.value).filter((item) => (item === value ? on : selected.has(item))),
        );
    const featureError = errors.features ?? Object.entries(errors).find(([key]) => key.startsWith('features.'))?.[1];

    return (
        <div className="grid gap-5">
            {showTills && (
                <FormField
                    id={id('max_registers')}
                    label="Tills allowed"
                    help={
                        tillsInUse !== undefined
                            ? `${tillsInUse} in use. Every till key of the branch carries this number (main till included).`
                            : 'Every till key of the branch carries this number (main till included).'
                    }
                    error={errors.max_registers}
                >
                    <Input
                        id={id('max_registers')}
                        type="number"
                        inputMode="numeric"
                        min={Math.max(1, tillsInUse ?? 1)}
                        max={options.maxRegisters}
                        value={values.max_registers}
                        onChange={(e) => setValue('max_registers', Math.max(0, Number.parseInt(e.target.value || '0', 10)))}
                        className="max-w-32 tabular-nums"
                        aria-invalid={!!errors.max_registers}
                    />
                </FormField>
            )}

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">Licence</legend>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {options.kinds.map((kind) => (
                        <label
                            key={kind.value}
                            className={cn(
                                'focus-within:ring-ring flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition-colors focus-within:ring-2',
                                values.kind === kind.value
                                    ? 'border-primary bg-primary-soft ring-primary/20 ring-1'
                                    : 'hover:border-border-strong hover:bg-subtle',
                            )}
                        >
                            <input
                                type="radio"
                                name={id('kind')}
                                value={kind.value}
                                checked={values.kind === kind.value}
                                onChange={() => setValue('kind', kind.value)}
                                className="accent-primary mt-1"
                            />
                            <span className="grid gap-0.5">
                                <span className="text-sm font-medium">{kind.label}</span>
                                <span className="text-muted-foreground text-[13px] leading-5">{kind.description}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {errors.kind && <p className="text-danger-foreground text-[13px]">{errors.kind}</p>}
            </fieldset>

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <FormField
                    id={id('length')}
                    label="Length"
                    optional={values.kind === 'trial'}
                    help={
                        values.kind === 'trial'
                            ? `Blank: the plan’s ${trialDays ?? 7}-day trial from each till’s first activation.`
                            : 'How long the paid licence runs, e.g. 12 months.'
                    }
                    error={errors.length ?? errors.length_unit}
                >
                    <div className="flex gap-2">
                        <Input
                            id={id('length')}
                            type="number"
                            inputMode="numeric"
                            min={1}
                            value={values.length}
                            onChange={(e) => setValue('length', e.target.value)}
                            className="w-24 tabular-nums"
                            aria-invalid={!!errors.length}
                        />
                        <Select value={values.length_unit || undefined} onValueChange={(value) => setValue('length_unit', value as LengthUnit)}>
                            <SelectTrigger aria-label="Length unit" className="w-32" aria-invalid={!!errors.length_unit}>
                                <SelectValue placeholder="Unit" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.units.map((unit) => (
                                    <SelectItem key={unit.value} value={unit.value}>
                                        {unit.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </FormField>
                {showStart && (
                    <FormField
                        id={id('valid_from')}
                        label="Starts"
                        optional
                        help="Blank: from each till’s first activation."
                        error={errors.valid_from}
                    >
                        <Input
                            id={id('valid_from')}
                            type="date"
                            value={values.valid_from}
                            disabled={values.length === ''}
                            onChange={(e) => setValue('valid_from', e.target.value)}
                            aria-invalid={!!errors.valid_from}
                        />
                    </FormField>
                )}
            </div>

            <fieldset className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <legend className="text-sm font-medium">Features</legend>
                    <div className="flex gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setValue(
                                    'features',
                                    options.features.map((f) => f.value),
                                )
                            }
                        >
                            All
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setValue('features', [])}>
                            None
                        </Button>
                    </div>
                </div>
                <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {options.features.map((feature) => (
                        <li key={feature.value}>
                            <label
                                htmlFor={id(`feature-${feature.value}`)}
                                className="hover:bg-subtle flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                            >
                                <Checkbox
                                    id={id(`feature-${feature.value}`)}
                                    checked={selected.has(feature.value)}
                                    onCheckedChange={(checked) => toggle(feature.value, checked === true)}
                                    className="mt-0.5"
                                />
                                <span className="grid min-w-0 gap-1">
                                    <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        {feature.label}
                                        {feature.tillName ? (
                                            <code className="text-muted-foreground font-mono text-[11px] font-normal">{feature.tillName}</code>
                                        ) : (
                                            <Badge variant="neutral">Portal only</Badge>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground text-[13px] leading-5">{feature.description}</span>
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
                {featureError ? (
                    <p className="text-danger-foreground text-[13px]">{featureError}</p>
                ) : (
                    <p className="text-muted-foreground text-[13px]">
                        The till gets each feature under the name shown. Multi-branch is set for the whole business.
                    </p>
                )}
            </fieldset>
        </div>
    );
}

/** The form values of a branch's saved settings. `planFeatures` fills in "the plan's" (null). */
export function licenceValues(
    licence: {
        maxRegisters: number;
        kind: LicenceKind;
        length: number | null;
        lengthUnit: LengthUnit | null;
        validFrom: string | null;
        features: string[] | null;
    },
    planFeatures: string[] = [],
): LicenceFormValues {
    return {
        max_registers: licence.maxRegisters,
        kind: licence.kind,
        length: licence.length === null ? '' : String(licence.length),
        length_unit: licence.lengthUnit ?? '',
        valid_from: licence.validFrom ? toShopDate(licence.validFrom) : '',
        features: licence.features ?? planFeatures,
    };
}

/** Values for the server: blank length/unit/start as null. */
export function licencePayload(values: LicenceFormValues): Record<string, unknown> {
    return {
        max_registers: values.max_registers,
        kind: values.kind,
        length: values.length === '' ? null : Number(values.length),
        length_unit: values.length === '' || values.length_unit === '' ? null : values.length_unit,
        valid_from: values.length === '' || values.valid_from === '' ? null : values.valid_from,
        features: values.features,
    };
}

/** "Trial · 1 year", "Trial · plan trial", "Full · 12 months from 1 Oct 2026". */
export function describeLicence(licence: { kind: LicenceKind; lengthLabel: string | null; validFrom: string | null }): string {
    const kind = licence.kind === 'full' ? 'Full' : 'Trial';
    const length = licence.lengthLabel ?? 'plan trial';
    const from = licence.validFrom
        ? ` from ${zonedDateFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(licence.validFrom))}`
        : '';

    return `${kind} · ${length}${from}`;
}

function toShopDate(iso: string): string {
    return zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));
}
