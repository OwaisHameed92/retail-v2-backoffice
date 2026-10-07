import { type LicenceFeatureOption } from '@/components/admin/licences/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

interface LicenceFeatureFieldsProps {
    /** Ticked features (used only when customised). */
    features: string[];
    /** The shop has its own features instead of the plan's. */
    custom: boolean;
    /** The chosen plan's features, without multi-branch. */
    planFeatures: string[];
    options: LicenceFeatureOption[];
    onChange: (custom: boolean, features: string[]) => void;
    error?: string;
    id: (name: string) => string;
}

/**
 * A branch's features (fix 2026-10-07): "From the plan" by default, so later plan changes reach the shop's tills;
 * "Customise for this shop" gives it its own list (saved only when it differs from the plan).
 */
export function LicenceFeatureFields({ features, custom, planFeatures, options, onChange, error, id }: LicenceFeatureFieldsProps) {
    const selected = new Set(features);
    const fromPlan = options.filter((feature) => planFeatures.includes(feature.value));
    const toggle = (value: string, on: boolean) =>
        onChange(
            true,
            options.map((feature) => feature.value).filter((item) => (item === value ? on : selected.has(item))),
        );

    return (
        <fieldset className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <legend className="flex items-center gap-2 text-sm font-medium">
                    Features
                    {custom ? <Badge variant="warning">Custom for this shop</Badge> : <Badge variant="info">From the plan</Badge>}
                </legend>
                <label htmlFor={id('custom_features')} className="flex cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                        id={id('custom_features')}
                        checked={custom}
                        // Starting from the plan's list either way: customising begins with what the shop has now.
                        onCheckedChange={(checked) => onChange(checked === true, planFeatures)}
                    />
                    Customise for this shop
                </label>
            </div>

            {custom ? (
                <>
                    <div className="flex justify-end gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    true,
                                    options.map((f) => f.value),
                                )
                            }
                        >
                            All
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange(true, [])}>
                            None
                        </Button>
                    </div>
                    <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {options.map((feature) => (
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
                </>
            ) : (
                <div className="bg-subtle rounded-lg border p-3">
                    {fromPlan.length === 0 ? (
                        <p className="text-muted-foreground text-sm">The plan has no extra features: core till only.</p>
                    ) : (
                        <ul className="flex flex-wrap gap-1.5" aria-label="The plan's features">
                            {fromPlan.map((feature) => (
                                <li key={feature.value} className="bg-card rounded-md border px-2 py-0.5 text-xs">
                                    {feature.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {error ? (
                <p className="text-danger-foreground text-[13px]">{error}</p>
            ) : (
                <p className="text-muted-foreground text-[13px]">
                    {custom
                        ? 'This shop keeps its own features, whatever the plan. Pick exactly the plan’s and it follows the plan instead.'
                        : 'The shop follows the plan: its keys carry the plan’s features, and keys issued after a plan change get the new ones.'}{' '}
                    Multi-branch is set for the whole business.
                </p>
            )}
        </fieldset>
    );
}
