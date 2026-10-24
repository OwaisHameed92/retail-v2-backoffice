import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, Package } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ImagePicker } from './image-picker';
import { ProductPicker } from './product-picker';
import { ToggleSwitch } from './toggle-switch';
import { type BannerLinkKey, type BuilderProps, type StoreBanner } from './types';

type BannerForm = {
    headline: string;
    text: string;
    button_label: string;
    link_type: BannerLinkKey;
    link_target: string;
    is_on: boolean;
    photo: File | null;
    remove_photo: boolean;
};

const blank: BannerForm = {
    headline: '',
    text: '',
    button_label: '',
    link_type: 'none',
    link_target: '',
    is_on: true,
    photo: null,
    remove_photo: false,
};

function initial(banner: StoreBanner | null): BannerForm {
    return banner === null
        ? blank
        : {
              headline: banner.headline ?? '',
              text: banner.text ?? '',
              button_label: banner.buttonLabel ?? '',
              link_type: banner.linkType,
              link_target: banner.linkTarget ?? '',
              is_on: banner.isOn,
              photo: null,
              remove_photo: false,
          };
}

const SELECT =
    'border-input bg-background focus-visible:ring-ring/40 h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:opacity-50';

/**
 * Adds or edits one home banner (OS-8): photo, headline, short text, button label and where it goes (a department, a
 * product from the picker, a search or an https address), on or off. Saved with its own request
 * (`app.online-store.banners.*`), so the store builder form keeps its own unsaved changes.
 */
export function BannerDialog({
    open,
    banner,
    onClose,
    props,
}: {
    open: boolean;
    /** Null: a new banner. */
    banner: StoreBanner | null;
    onClose: () => void;
    props: Pick<BuilderProps, 'linkOptions' | 'departments' | 'template'>;
}) {
    const form = useForm<BannerForm>(initial(banner));
    const errors = form.errors as Record<string, string | undefined>;
    const [picking, setPicking] = useState(false);
    const [productName, setProductName] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            form.setDefaults(initial(banner));
            form.setData(initial(banner));
            form.clearErrors();
            setProductName(banner?.linkType === 'product' ? banner.linkLabel : null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, banner?.id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // The dialog is portalled, but React events still bubble to the builder's own form: keep it out of it.
        e.stopPropagation();
        form.transform((data) => ({ ...data, is_on: data.is_on ? '1' : '0', remove_photo: data.remove_photo ? '1' : '0' }));
        form.post(banner ? route('app.online-store.banners.update', banner.id) : route('app.online-store.banners.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };
    const setType = (type: BannerLinkKey) => {
        form.setData((d) => ({ ...d, link_type: type, link_target: '' }));
        setProductName(null);
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && !form.processing && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{banner ? 'Edit banner' : 'Add a banner'}</DialogTitle>
                        <DialogDescription>Banners take turns at the top of your home page. Keep the headline short and bold.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label>Photo</Label>
                        <ImagePicker
                            label="Banner photo"
                            shape="wide"
                            current={banner?.photoUrl ?? null}
                            file={form.data.photo}
                            removed={form.data.remove_photo}
                            onPick={(f) => form.setData('photo', f)}
                            onRemove={(r) => form.setData('remove_photo', r)}
                            fallback={props.template?.photo}
                            fallbackNote={props.template ? `${props.template.label} photo` : undefined}
                            error={errors.photo}
                            disabled={form.processing}
                            help="A wide photo, at least 1600 pixels wide. JPG, PNG or WebP, up to 4 MB. Without one, the template's photo is used."
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="banner-headline">Headline</Label>
                        <Input
                            id="banner-headline"
                            value={form.data.headline}
                            maxLength={160}
                            placeholder="Fresh bread, baked every morning."
                            aria-invalid={!!errors.headline}
                            disabled={form.processing}
                            onChange={(e) => form.setData('headline', e.target.value)}
                        />
                        {errors.headline && <p className="text-danger-foreground text-xs">{errors.headline}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="banner-text">Short text</Label>
                        <Textarea
                            id="banner-text"
                            rows={2}
                            value={form.data.text}
                            maxLength={240}
                            placeholder="Optional. One or two sentences under the headline."
                            aria-invalid={!!errors.text}
                            disabled={form.processing}
                            onChange={(e) => form.setData('text', e.target.value)}
                        />
                        <p className={errors.text ? 'text-danger-foreground text-xs' : 'text-muted-foreground text-xs'}>
                            {errors.text ?? `${form.data.text.length} of 240 characters.`}
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="banner-button">Button label</Label>
                            <Input
                                id="banner-button"
                                value={form.data.button_label}
                                maxLength={40}
                                placeholder="Start shopping"
                                aria-invalid={!!errors.button_label}
                                disabled={form.processing}
                                onChange={(e) => form.setData('button_label', e.target.value)}
                            />
                            {errors.button_label && <p className="text-danger-foreground text-xs">{errors.button_label}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="banner-link">Button goes to</Label>
                            <select
                                id="banner-link"
                                className={SELECT}
                                value={form.data.link_type}
                                disabled={form.processing}
                                onChange={(e) => setType(e.target.value as BannerLinkKey)}
                            >
                                {props.linkOptions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {form.data.link_type === 'department' && (
                        <div className="grid gap-2">
                            <Label htmlFor="banner-department">Department</Label>
                            <select
                                id="banner-department"
                                className={SELECT}
                                value={form.data.link_target}
                                aria-invalid={!!errors.link_target}
                                disabled={form.processing}
                                onChange={(e) => form.setData('link_target', e.target.value)}
                            >
                                <option value="">Choose a department</option>
                                {props.departments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    {form.data.link_type === 'product' && (
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="bg-muted flex min-h-9 min-w-0 flex-1 items-center gap-2 rounded-md px-3 text-sm">
                                <Package className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                <span className="truncate">{productName ?? 'No product chosen'}</span>
                            </span>
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => setPicking(true)}>
                                {form.data.link_target ? 'Change product' : 'Choose product'}
                            </Button>
                        </div>
                    )}
                    {(form.data.link_type === 'search' || form.data.link_type === 'url') && (
                        <div className="grid gap-2">
                            <Label htmlFor="banner-target">{form.data.link_type === 'search' ? 'Search for' : 'Web address'}</Label>
                            <Input
                                id="banner-target"
                                value={form.data.link_target}
                                maxLength={form.data.link_type === 'search' ? 80 : 500}
                                inputMode={form.data.link_type === 'url' ? 'url' : undefined}
                                placeholder={form.data.link_type === 'search' ? 'e.g. bread' : 'https://'}
                                aria-invalid={!!errors.link_target}
                                disabled={form.processing}
                                onChange={(e) => form.setData('link_target', e.target.value)}
                            />
                        </div>
                    )}
                    {errors.link_target && <p className="text-danger-foreground -mt-3 text-xs">{errors.link_target}</p>}

                    <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
                        <div>
                            <Label htmlFor="banner-on">Show on the home page</Label>
                            <p className="text-muted-foreground text-xs leading-5">Turn off to keep it for later.</p>
                        </div>
                        <ToggleSwitch
                            id="banner-on"
                            checked={form.data.is_on}
                            disabled={form.processing}
                            onCheckedChange={(on) => form.setData('is_on', on)}
                        />
                    </div>

                    {(errors.banners || errors.banner || errors.store) && (
                        <p className="text-danger-foreground text-sm">{errors.banners ?? errors.banner ?? errors.store}</p>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing || (banner !== null && !form.isDirty)}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            {banner ? 'Save banner' : 'Add banner'}
                        </Button>
                    </DialogFooter>
                </form>
                <ProductPicker
                    open={picking}
                    onOpenChange={setPicking}
                    title="Choose the product the button opens"
                    onPick={(product) => {
                        form.setData('link_target', product.id);
                        setProductName(product.name);
                        setPicking(false);
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
