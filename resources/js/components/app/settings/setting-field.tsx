import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Store, Undo2 } from 'lucide-react';
import { displayValue, type SettingDefinition, type ShopSettingsProps } from './types';

const INHERIT = 'inherit';

interface SettingFieldProps {
    definition: SettingDefinition;
    value: string;
    onChange: (value: string) => void;
    /** One shop: what it gets without a value of its own. */
    inherited?: ShopSettingsProps['inherited'][string];
    /** Every shop: shops with a value of their own. */
    overrides?: string[];
    isShop: boolean;
    error?: string;
}

/** What applies when nothing is set at this level: "Every shop: On", "Till default: £0.00", "Till default". */
function fallback(definition: SettingDefinition, inherited: SettingFieldProps['inherited'], isShop: boolean): string {
    if (isShop && inherited?.from === 'everyShop') {
        return `Every shop: ${displayValue(definition, inherited.value) ?? 'blank'}`;
    }
    const byDefault = displayValue(definition, inherited?.value ?? definition.default);

    return byDefault ? `Till default: ${byDefault}` : 'Till default';
}

/** One till setting: an On/Off choice, one of the till's options, a time, a number with its unit, or text. Blank = use the wider setting. */
export function SettingField({ definition, value, onChange, inherited, overrides, isShop, error }: SettingFieldProps) {
    const id = `setting-${definition.key.replace(/[^a-z0-9]/gi, '-')}`;
    const inheritLabel = fallback(definition, inherited, isShop);
    const invalid = error ? true : undefined;
    const describedBy = error ? `${id}-error` : `${id}-help`;

    const aside =
        isShop && value !== '' ? (
            <Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => onChange('')}>
                <Undo2 className="size-3.5" aria-hidden />
                Use {inherited?.from === 'everyShop' ? "every shop's" : 'default'}
            </Button>
        ) : undefined;

    const control = (() => {
        switch (definition.type) {
            case 'bool':
                return (
                    <Select value={value === '' ? INHERIT : value} onValueChange={(v) => onChange(v === INHERIT ? '' : v)}>
                        <SelectTrigger id={id} aria-invalid={invalid} aria-describedby={describedBy}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={INHERIT}>{inheritLabel}</SelectItem>
                            <SelectItem value="true">On</SelectItem>
                            <SelectItem value="false">Off</SelectItem>
                        </SelectContent>
                    </Select>
                );
            case 'choice':
                return (
                    <Select value={value === '' ? INHERIT : value} onValueChange={(v) => onChange(v === INHERIT ? '' : v)}>
                        <SelectTrigger id={id} aria-invalid={invalid} aria-describedby={describedBy}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={INHERIT}>{inheritLabel}</SelectItem>
                            {(definition.options ?? []).map((option) => (
                                <SelectItem key={option} value={option}>
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                );
            case 'time':
                return (
                    <Input
                        id={id}
                        type="time"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        className="tabular-nums"
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                );
            case 'multiline':
                return (
                    <Textarea
                        id={id}
                        rows={3}
                        value={value}
                        maxLength={definition.max}
                        placeholder={isShop && inherited?.from === 'everyShop' ? (inherited.value ?? '') : inheritLabel}
                        onChange={(e) => onChange(e.target.value)}
                        aria-invalid={invalid} aria-describedby={describedBy}
                    />
                );
            case 'text':
                return (
                    <Input
                        id={id}
                        value={value}
                        maxLength={definition.max}
                        placeholder={isShop && inherited?.from === 'everyShop' ? (inherited.value ?? '') : inheritLabel}
                        onChange={(e) => onChange(e.target.value)}
                        aria-invalid={invalid} aria-describedby={describedBy}
                    />
                );
            default: {
                const prefix = definition.type === 'money' || definition.unit === '£' ? '£' : null;
                const suffix = definition.type === 'percent' ? '%' : definition.unit && definition.unit !== '£' ? definition.unit : null;

                return (
                    <div className="relative">
                        {prefix && <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm">{prefix}</span>}
                        <Input
                            id={id}
                            inputMode={definition.type === 'int' ? 'numeric' : 'decimal'}
                            value={value}
                            placeholder={inheritLabel}
                            onChange={(e) => onChange(e.target.value)}
                            className={cn('tabular-nums', prefix && 'pl-7', suffix && 'pr-20')}
                            aria-invalid={invalid} aria-describedby={describedBy}
                        />
                        {suffix && (
                            <span className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm">{suffix}</span>
                        )}
                    </div>
                );
            }
        }
    })();

    return (
        <FormField
            id={id}
            label={definition.label}
            help={definition.help}
            error={error}
            labelAside={aside}
            className={definition.type === 'multiline' ? 'sm:col-span-2' : undefined}
        >
            {control}
            {overrides && overrides.length > 0 && (
                <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                    <Store className="mt-px size-3.5 shrink-0" aria-hidden />
                    <span>
                        {overrides.join(', ')} {overrides.length === 1 ? 'has its' : 'have their'} own setting.
                    </span>
                </p>
            )}
        </FormField>
    );
}
