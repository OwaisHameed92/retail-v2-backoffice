import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeZoneLabel } from '@/lib/country';
import { router, useForm } from '@inertiajs/react';
import { Copy, LoaderCircle, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { type ShopHours } from './types';

const NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

type DayForm = {
    closed: boolean;
    opens: string;
    closes: string;
};

function initialDays(shop: ShopHours, defaults: { opens: string; closes: string }): DayForm[] {
    return NAMES.map((_, i) => {
        const day = shop.days?.[i];

        return day ? { closed: day.closed, opens: day.opens ?? defaults.opens, closes: day.closes ?? defaults.closes } : { closed: false, ...defaults };
    });
}

interface HoursDialogProps {
    shop: ShopHours;
    defaults: { opens: string; closes: string };
    canApplyToEveryShop: boolean;
}

/** Edit one shop's week: open or closed per day, opening and closing times; optionally the same week for every shop. */
export function HoursDialog({ shop, defaults, canApplyToEveryShop }: HoursDialogProps) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ days: DayForm[]; everyShop: boolean }>({ days: initialDays(shop, defaults), everyShop: false });
    const errors = form.errors as Record<string, string | undefined>;

    const setDay = (index: number, patch: Partial<DayForm>) => form.setData('days', form.data.days.map((d, i) => (i === index ? { ...d, ...patch } : d)));
    const copyMonday = () => form.setData('days', form.data.days.map(() => ({ ...form.data.days[0] })));
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('app.calendar.hours.update', shop.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData({ days: initialDays(shop, defaults), everyShop: false });
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant={shop.days ? 'outline' : 'default'} size="sm">
                    {shop.days ? <Pencil /> : <Plus />}
                    {shop.days ? 'Edit hours' : 'Set hours'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Opening hours · {shop.name}</DialogTitle>
                        <DialogDescription>
                            Times are {timeZoneLabel()} time. A closing time before the opening time means the shop closes after midnight. The shop&apos;s tills get
                            the hours at their next sync.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="divide-border grid divide-y rounded-lg border">
                        {form.data.days.map((day, i) => {
                            const weekday = i + 1;
                            const opensError = errors[`days.${weekday}.opens`] ?? errors[`days.${i}.opens`];
                            const closesError = errors[`days.${weekday}.closes`] ?? errors[`days.${i}.closes`];

                            return (
                                <div key={NAMES[i]} className="grid grid-cols-1 gap-2 px-3 py-2.5 sm:grid-cols-[8rem_1fr] sm:items-center">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`open-${weekday}`}
                                            checked={!day.closed}
                                            onCheckedChange={(on) => setDay(i, { closed: on !== true })}
                                            aria-label={`Open on ${NAMES[i]}`}
                                        />
                                        <Label htmlFor={`open-${weekday}`} className="font-medium">
                                            {NAMES[i]}
                                        </Label>
                                    </div>
                                    {day.closed ? (
                                        <p className="text-muted-foreground text-sm">Closed all day</p>
                                    ) : (
                                        <div className="grid gap-1">
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    type="time"
                                                    className="h-9 w-32"
                                                    value={day.opens}
                                                    aria-label={`${NAMES[i]} opens`}
                                                    aria-invalid={!!opensError}
                                                    onChange={(e) => setDay(i, { opens: e.target.value })}
                                                />
                                                <span className="text-muted-foreground text-sm">to</span>
                                                <Input
                                                    type="time"
                                                    className="h-9 w-32"
                                                    value={day.closes}
                                                    aria-label={`${NAMES[i]} closes`}
                                                    aria-invalid={!!closesError}
                                                    onChange={(e) => setDay(i, { closes: e.target.value })}
                                                />
                                                {day.closes && day.opens && day.closes < day.opens && (
                                                    <span className="text-muted-foreground text-xs">next day</span>
                                                )}
                                            </div>
                                            {(opensError || closesError) && <p className="text-destructive text-xs">{opensError ?? closesError}</p>}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    {errors.days && <p className="text-destructive text-sm">{errors.days}</p>}

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Button type="button" variant="ghost" size="sm" onClick={copyMonday}>
                            <Copy />
                            Use Monday&apos;s hours every day
                        </Button>
                        {canApplyToEveryShop && (
                            <div className="flex items-center gap-2">
                                <Checkbox id="every-shop" checked={form.data.everyShop} onCheckedChange={(on) => form.setData('everyShop', on === true)} />
                                <Label htmlFor="every-shop" className="text-sm font-normal">
                                    Save for every shop
                                </Label>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:justify-between">
                        {shop.days ? (
                            <ConfirmDialog
                                trigger={
                                    <Button type="button" variant="ghost" className="text-destructive">
                                        Remove hours
                                    </Button>
                                }
                                title={`Remove ${shop.name}'s opening hours?`}
                                description="The tills' opening hours setting is removed and till alerts go back to 08:00–20:00 every day."
                                confirmLabel="Remove hours"
                                destructive
                                onConfirm={() =>
                                    new Promise<void>((resolve) =>
                                        router.put(
                                            route('app.calendar.hours.update', shop.id),
                                            { clear: true },
                                            { preserveScroll: true, onFinish: () => resolve(), onSuccess: () => setOpen(false) },
                                        ),
                                    )
                                }
                            />
                        ) : (
                            <span />
                        )}
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                Save hours
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
