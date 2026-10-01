import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { BellOff, CircleAlert, LoaderCircle, Store } from 'lucide-react';
import { type FormEvent } from 'react';

type Delivery = 'off' | 'digest' | 'immediate';

interface AlertTypeRow {
    value: string;
    label: string;
    description: string;
    urgent: boolean;
    delivery: Delivery;
    default: Delivery;
    options: { value: Delivery; label: string }[];
}

interface NotificationsProps {
    business: string;
    email: string;
    role: string;
    types: AlertTypeRow[];
    shops: { id: string; name: string }[];
    allShops: boolean;
    selectedShops: string[];
    lockedShop: string | null;
    digestTime: string;
}

type FormData = {
    deliveries: Record<string, Delivery>;
    allShops: boolean;
    shops: string[];
};

/** Settings → Notifications (module 7.8): which alert emails the user gets from this business, and for which shops. */
export default function Notifications({ business, email, role, types, shops, allShops, selectedShops, lockedShop, digestTime }: NotificationsProps) {
    const { data, setData, put, processing, errors, isDirty, reset } = useForm<FormData>({
        deliveries: Object.fromEntries(types.map((type) => [type.value, type.delivery])),
        allShops,
        shops: selectedShops,
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('app.notifications.settings.update'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const toggleShop = (id: string, checked: boolean) => setData('shops', checked ? [...data.shops, id] : data.shops.filter((shop) => shop !== id));

    return (
        <AppLayout>
            <Head title="Notifications" />

            <SettingsLayout>
                <form onSubmit={submit} className="space-y-10">
                    <div className="space-y-6">
                        <HeadingSmall
                            title={`Alerts from ${business}`}
                            description={`Sent to ${email}. Urgent alerts can come straight away; everything else comes in one summary at ${digestTime} when something needs a look.`}
                        />

                        {types.length === 0 ? (
                            <div className="flex items-start gap-3 rounded-lg border p-4">
                                <BellOff className="text-muted-foreground mt-0.5 size-5 shrink-0" aria-hidden />
                                <p className="text-muted-foreground text-sm">
                                    Your role ({role}) does not get alert emails. Ask the owner if you need them.
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {types.map((type) => (
                                    <li key={type.value} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                                        <div className="min-w-0">
                                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                {type.label}
                                                {type.urgent && (
                                                    <Badge variant="outline" className="text-[11px]">
                                                        Urgent
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground mt-0.5 text-sm">{type.description}</p>
                                            {fieldErrors[`deliveries.${type.value}`] && (
                                                <p className="text-destructive mt-1 text-sm">{fieldErrors[`deliveries.${type.value}`]}</p>
                                            )}
                                        </div>
                                        <DeliveryPicker
                                            label={type.label}
                                            value={data.deliveries[type.value]}
                                            options={type.options}
                                            onChange={(value) => setData('deliveries', { ...data.deliveries, [type.value]: value })}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {types.length > 0 && (
                        <div className="space-y-4 border-t pt-8">
                            <HeadingSmall title="Shops" description="Alerts about a shop only come to you for the shops chosen here." />

                            {lockedShop ? (
                                <div className="bg-muted/50 flex items-center gap-3 rounded-lg border p-4 text-sm">
                                    <Store className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                    <span>
                                        You get alerts for <span className="font-medium">{lockedShop}</span> only, the shop you work in.
                                    </span>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <label className="flex items-center gap-3 text-sm font-medium">
                                        <Checkbox checked={data.allShops} onCheckedChange={(checked) => setData('allShops', checked === true)} />
                                        Every shop, including shops added later
                                    </label>

                                    {!data.allShops && (
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {shops.map((shop) => (
                                                <label
                                                    key={shop.id}
                                                    className="hover:bg-muted/50 flex items-center gap-3 rounded-lg border px-3 py-2.5 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={data.shops.includes(shop.id)}
                                                        onCheckedChange={(checked) => toggleShop(shop.id, checked === true)}
                                                    />
                                                    {shop.name}
                                                </label>
                                            ))}
                                        </div>
                                    )}

                                    {errors.shops && (
                                        <Alert variant="destructive">
                                            <CircleAlert className="size-4" />
                                            <AlertDescription>{errors.shops}</AlertDescription>
                                        </Alert>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {types.length > 0 && (
                        <div className="flex flex-col-reverse gap-2 border-t pt-6 sm:flex-row sm:items-center sm:justify-end">
                            {isDirty && <p className="text-muted-foreground text-sm sm:mr-auto">You have unsaved changes.</p>}
                            <Button type="button" variant="outline" disabled={!isDirty || processing} onClick={() => reset()}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="animate-spin" />}
                                Save notifications
                            </Button>
                        </div>
                    )}
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

/** Off / Daily digest / Straight away, as a segmented control (radio group). */
function DeliveryPicker({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: Delivery;
    options: { value: Delivery; label: string }[];
    onChange: (value: Delivery) => void;
}) {
    return (
        <div
            role="radiogroup"
            aria-label={`${label}: how to tell you`}
            className="bg-muted inline-flex shrink-0 gap-1 self-start rounded-lg border p-1 sm:self-auto"
        >
            {options.map((option) => (
                <Label
                    key={option.value}
                    className={cn(
                        'has-[:focus-visible]:ring-ring flex h-8 cursor-pointer items-center rounded-md px-3 text-sm font-medium transition-colors duration-150 has-[:focus-visible]:ring-2',
                        value === option.value
                            ? 'bg-card text-foreground shadow-card ring-border ring-1'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <input
                        type="radio"
                        className="sr-only"
                        name={`delivery-${label}`}
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onChange(option.value)}
                    />
                    {option.label}
                </Label>
            ))}
        </div>
    );
}
