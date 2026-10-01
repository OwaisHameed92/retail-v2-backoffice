import { DepartmentMapping, mappingPayload } from '@/components/app/catalogue/pricing-fields';
import { type StarterPackProps } from '@/components/app/catalogue/types';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Info, LoaderCircle, Sparkles } from 'lucide-react';
import { useState } from 'react';

const number = new Intl.NumberFormat('en-GB');

/** Onboarding: pick the kind of shop, keep the departments you stock, and add a ready-made range in one go. */
export default function StarterPackPage({
    packs,
    pack,
    suggested,
    departments,
    owned,
    productCount,
    yourDepartments,
    hasVatRates,
}: StarterPackProps) {
    const suggestedDepartments = departments.filter((d) => d.included).map((d) => d.value);
    const [ticked, setTicked] = useState<{ pack: string; values: string[] }>({ pack, values: suggestedDepartments });
    const include = ticked.pack === pack ? ticked.values : suggestedDepartments;
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const chosen = departments.filter((d) => include.includes(d.value));
    const total = chosen.reduce((sum, d) => sum + d.count, 0);
    const pick = (value: string) =>
        router.get(
            route('app.products.starter'),
            { pack: value },
            { only: ['pack', 'departments'], preserveScroll: true, preserveState: true, replace: true },
        );
    const toggle = (value: string, on: boolean) => setTicked({ pack, values: on ? [...include, value] : include.filter((v) => v !== value) });

    const submit = () =>
        router.post(
            route('app.products.starter.add'),
            { pack, include, price_rule: 'rrp', end_in_9: false, departments: mappingPayload(mapping) },
            { onStart: () => setProcessing(true), onFinish: () => setProcessing(false), onError: (errs) => setErrors(errs) },
        );

    return (
        <AppLayout>
            <Head title="Starter pack" />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title="Starter pack"
                    icon={Sparkles}
                    description="Start with the products a shop like yours usually sells instead of keying them in. They are priced at RRP with no cost yet: change prices, add costs or archive any of them later."
                    back={{ href: route('app.products.index'), label: 'Products' }}
                />

                {!hasVatRates && (
                    <Alert variant="warning">
                        <Info />
                        <AlertTitle>Connect a till first</AlertTitle>
                        <AlertDescription>
                            Your VAT rates arrive from your till at its first sync. Once it has synced, come back to add your starter pack.
                        </AlertDescription>
                    </Alert>
                )}

                <SectionCard title="1. What kind of shop is it?" description="We suggest one from your business details.">
                    <div role="radiogroup" aria-label="Kind of shop" className="grid gap-3 sm:grid-cols-2">
                        {packs.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={pack === option.value}
                                onClick={() => pick(option.value)}
                                className={cn(
                                    'hover:bg-subtle focus-visible:ring-ring/40 rounded-lg border p-4 text-left transition-colors outline-none focus-visible:ring-2',
                                    pack === option.value && 'border-primary bg-primary-soft/40 ring-primary/20 ring-1',
                                )}
                            >
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    {option.label}
                                    {option.value === suggested && <Badge variant="info">Suggested</Badge>}
                                </span>
                                <span className="text-muted-foreground mt-1 block text-sm">{option.description}</span>
                            </button>
                        ))}
                    </div>
                </SectionCard>

                <SectionCard title="2. Departments" description="Untick anything you do not sell.">
                    {departments.length === 0 ? (
                        <p className="text-muted-foreground text-sm">The SSPOS catalogue has no starter products yet. Please check again soon.</p>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {departments.map((d) => (
                                <label key={d.value} className="hover:bg-subtle flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3">
                                    <Checkbox checked={include.includes(d.value)} onCheckedChange={(state) => toggle(d.value, state === true)} />
                                    <span className="flex-1 text-sm font-medium">{d.label}</span>
                                    <span className="text-muted-foreground text-sm tabular-nums">{number.format(d.count)}</span>
                                </label>
                            ))}
                        </div>
                    )}
                    {errors.include && <p className="text-danger-foreground mt-2 text-[13px]">{errors.include}</p>}
                    {errors.departments && <p className="text-danger-foreground mt-2 text-[13px]">{errors.departments}</p>}
                </SectionCard>

                {chosen.length > 0 && (
                    <SectionCard
                        title="3. Where they file"
                        description="Each catalogue department goes into one of yours, or a new one of the same name."
                    >
                        <DepartmentMapping departments={chosen} yours={yourDepartments} value={mapping} onChange={setMapping} />
                    </SectionCard>
                )}

                <StickyFormBar
                    message={
                        owned > 0 || productCount > 0
                            ? `Products whose barcode you already have are skipped (${number.format(owned)} of the starter range).`
                            : 'Every shop gets them at its next sync.'
                    }
                >
                    <Button variant="outline" asChild>
                        <Link href={route('app.products.index')}>Cancel</Link>
                    </Button>
                    <Button onClick={submit} disabled={processing || !hasVatRates || total === 0}>
                        {processing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Sparkles />}
                        Add {number.format(total)} products
                    </Button>
                </StickyFormBar>
            </div>
        </AppLayout>
    );
}
