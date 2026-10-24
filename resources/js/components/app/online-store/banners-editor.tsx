import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ImagePlus, Link2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { BannerDialog } from './banner-dialog';
import { ToggleSwitch } from './toggle-switch';
import { type BuilderProps, type StoreBanner } from './types';

const LINKS: Record<StoreBanner['linkType'], string> = {
    none: 'Start shopping',
    department: 'Department',
    product: 'Product',
    search: 'Search',
    url: 'Web address',
};

/**
 * The store builder's home banners (OS-8): up to 6, each with its photo, headline and button; turn on or off, move up
 * or down, edit, delete. Every change saves at once (its own request), so it never mixes with the form's unsaved
 * changes. No banners yet: the template's photo shows.
 */
export function BannersEditor({ props, disabled }: { props: Omit<BuilderProps, 'store'>; disabled: boolean }) {
    const { banners, bannerMax, template } = props;
    const [editing, setEditing] = useState<StoreBanner | null | 'new'>(null);
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const locked = disabled || busy;

    const move = (index: number, by: -1 | 1) => {
        const ids = banners.map((b) => b.id);
        [ids[index], ids[index + by]] = [ids[index + by], ids[index]];
        router.put(route('app.online-store.banners.order'), { ids }, options);
    };
    const toggle = (banner: StoreBanner, on: boolean) =>
        router.post(
            route('app.online-store.banners.update', banner.id),
            {
                headline: banner.headline ?? '',
                text: banner.text ?? '',
                button_label: banner.buttonLabel ?? '',
                link_type: banner.linkType,
                link_target: banner.linkTarget ?? '',
                is_on: on ? '1' : '0',
            },
            options,
        );

    return (
        <div className="grid gap-3">
            {banners.length === 0 ? (
                <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-4 sm:flex-row sm:items-center">
                    {template && <img src={template.photo} alt="" className="aspect-[16/7] w-full rounded-md object-cover sm:w-40" />}
                    <p className="text-muted-foreground flex-1 text-sm">
                        No banners yet, so the {template?.label ?? 'template'} photo shows. Add your own: a seasonal offer, fresh bread, a new range.
                    </p>
                </div>
            ) : (
                <ol className="divide-border grid divide-y rounded-lg border">
                    {banners.map((banner, i) => (
                        <li key={banner.id} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                <span className="bg-muted relative aspect-[16/9] w-24 shrink-0 overflow-hidden rounded-md border sm:w-32">
                                    <img src={banner.photoUrl ?? template?.photo} alt="" className="size-full object-cover" />
                                    {!banner.photoUrl && (
                                        <span className="absolute inset-x-0 bottom-0 bg-black/55 px-1 text-[10px] text-white">Template photo</span>
                                    )}
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">{banner.headline ?? 'The template headline'}</p>
                                    <p className="text-muted-foreground flex items-center gap-1 truncate text-xs">
                                        <Link2 className="size-3 shrink-0" aria-hidden />
                                        {banner.buttonLabel ?? 'Start shopping'} → {LINKS[banner.linkType]}
                                        {banner.linkLabel ? `: ${banner.linkLabel}` : ''}
                                    </p>
                                    {!banner.isOn && (
                                        <Badge variant="neutral" className="mt-1">
                                            Off
                                        </Badge>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center gap-1 self-end sm:self-auto">
                                <ToggleSwitch
                                    checked={banner.isOn}
                                    disabled={locked}
                                    label={`Show banner ${i + 1} on the home page`}
                                    onCheckedChange={(on) => toggle(banner, on)}
                                    className="mr-2"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={locked || i === 0}
                                    onClick={() => move(i, -1)}
                                    aria-label={`Move banner ${i + 1} up`}
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={locked || i === banners.length - 1}
                                    onClick={() => move(i, 1)}
                                    aria-label={`Move banner ${i + 1} down`}
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={locked}
                                    onClick={() => setEditing(banner)}
                                    aria-label={`Edit banner ${i + 1}`}
                                >
                                    <Pencil />
                                </Button>
                                <ConfirmDialog
                                    trigger={
                                        <Button type="button" variant="ghost" size="icon" disabled={locked} aria-label={`Delete banner ${i + 1}`}>
                                            <Trash2 />
                                        </Button>
                                    }
                                    title="Delete this banner?"
                                    description="It leaves the home page now, with its photo."
                                    confirmLabel="Delete banner"
                                    destructive
                                    onConfirm={() =>
                                        new Promise<void>((resolve) =>
                                            router.delete(route('app.online-store.banners.destroy', banner.id), {
                                                ...options,
                                                onFinish: () => {
                                                    setBusy(false);
                                                    resolve();
                                                },
                                            }),
                                        )
                                    }
                                />
                            </div>
                        </li>
                    ))}
                </ol>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="outline" disabled={locked || banners.length >= bannerMax} onClick={() => setEditing('new')}>
                    {banners.length === 0 ? <ImagePlus /> : <Plus />}
                    Add a banner
                </Button>
                <span className="text-muted-foreground text-xs">
                    {banners.length} of {bannerMax}. Banners save straight away; they take turns every few seconds.
                </span>
            </div>
            <BannerDialog
                open={editing !== null}
                banner={editing === 'new' ? null : editing}
                onClose={() => setEditing(null)}
                props={{ linkOptions: props.linkOptions, departments: props.departments, template }}
            />
        </div>
    );
}
