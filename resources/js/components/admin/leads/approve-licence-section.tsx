import { type TrialShopInput } from '@/components/admin/leads/types';
import { LicenceFormFields, type LicenceFormValues } from '@/components/admin/licences/licence-form-fields';
import { type LicenceOptions } from '@/components/admin/licences/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

interface ApproveLicenceSectionProps {
    shops: TrialShopInput[];
    onTillsAllowed: (index: number, value: number) => void;
    values: LicenceFormValues;
    setValue: <K extends keyof LicenceFormValues>(key: K, value: LicenceFormValues[K]) => void;
    errors: Record<string, string | undefined>;
    options: LicenceOptions;
    trialDays: number;
}

/**
 * The licence form in "Approve trial" (module 1.11): tills allowed per shop (at least its tills), and the kind,
 * length and features every key carries. Folded by default: the plan's defaults are usually right.
 */
export function ApproveLicenceSection({ shops, onTillsAllowed, values, setValue, errors, options, trialDays }: ApproveLicenceSectionProps) {
    const [open, setOpen] = useState(false);
    const summary = `${values.kind === 'full' ? 'Full' : 'Trial'} · ${values.length === '' ? `${trialDays}-day trial` : `${values.length} ${values.length_unit}`} · ${values.features.length} features`;

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="grid gap-3 rounded-xl border p-4">
            <div className="flex items-center justify-between gap-3">
                <div className="grid gap-0.5">
                    <h3 className="text-sm font-semibold">Licence form</h3>
                    <p className="text-muted-foreground text-[13px]">{summary}</p>
                </div>
                <CollapsibleTrigger asChild>
                    <Button type="button" variant="ghost" size="sm" aria-expanded={open}>
                        {open ? 'Hide' : 'Change'}
                        <ChevronDown className={open ? 'rotate-180 transition-transform' : 'transition-transform'} />
                    </Button>
                </CollapsibleTrigger>
            </div>
            <CollapsibleContent className="grid gap-5 pt-2">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {shops.map((shop, index) => (
                        <FormField
                            key={index}
                            id={`shop-${index}-allowed`}
                            label={`Tills allowed in ${shop.name || `shop ${index + 1}`}`}
                            help={`${shop.tills} added now.`}
                            error={errors[`shops.${index}.tills_allowed`]}
                        >
                            <Input
                                id={`shop-${index}-allowed`}
                                type="number"
                                inputMode="numeric"
                                min={shop.tills}
                                max={options.maxRegisters}
                                value={shop.tills_allowed ?? shop.tills}
                                onChange={(e) => onTillsAllowed(index, Math.max(shop.tills, Number.parseInt(e.target.value || '0', 10)))}
                                className="max-w-28 tabular-nums"
                            />
                        </FormField>
                    ))}
                </div>
                <LicenceFormFields
                    values={values}
                    setValue={setValue}
                    errors={errors}
                    options={options}
                    showTills={false}
                    showStart={false}
                    trialDays={trialDays}
                    idPrefix="approve-licence"
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
