import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect } from 'react';
import { ImagePicker } from './image-picker';
import { ToggleSwitch } from './toggle-switch';
import { type OnlineProductRow } from './types';

type DetailsForm = { hidden: boolean; not_featured: boolean; description: string; allergen_info: string; photo: File | null; remove_photo: boolean };

const initial = (product: OnlineProductRow): DetailsForm => ({
    hidden: product.hidden,
    not_featured: product.notFeatured,
    description: product.description ?? '',
    allergen_info: product.allergenInfo ?? '',
    photo: null,
    remove_photo: false,
});

interface ProductDialogProps {
    product: OnlineProductRow | null;
    onClose: () => void;
    descriptionMax: number;
}

/**
 * A product's online details: shown or hidden, photo, short description and (OS-8, audit OSA-3) ingredients and
 * allergens shown on the product page before purchase. The till's product is never changed.
 */
export function ProductDialog({ product, onClose, descriptionMax }: ProductDialogProps) {
    const form = useForm<DetailsForm>({ hidden: false, not_featured: false, description: '', allergen_info: '', photo: null, remove_photo: false });
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (product) {
            form.setDefaults(initial(product));
            form.setData(initial(product));
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [product?.id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!product) {
            return;
        }
        form.post(route('app.online-store.products.update', product.id), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={product !== null} onOpenChange={(open) => !open && !form.processing && onClose()}>
            <DialogContent className="sm:max-w-lg">
                {product && (
                    <form onSubmit={submit} className="grid gap-5">
                        <DialogHeader>
                            <DialogTitle>{product.name}</DialogTitle>
                            <DialogDescription>How it shows in your online store. The product on your tills does not change.</DialogDescription>
                        </DialogHeader>

                        <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
                            <div>
                                <Label htmlFor="product-shown">Show online</Label>
                                <p className="text-muted-foreground text-xs leading-5">
                                    {product.reasons.length > 0
                                        ? `Not shown at this shop anyway: ${product.reasons.join(', ').toLowerCase()}.`
                                        : 'Hidden products never appear in the store.'}
                                </p>
                            </div>
                            <ToggleSwitch
                                id="product-shown"
                                checked={!form.data.hidden}
                                disabled={form.processing}
                                onCheckedChange={(on) => form.setData('hidden', !on)}
                            />
                        </div>

                        <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
                            <div>
                                <Label htmlFor="product-featured">Can be featured on the home page</Label>
                                <p className="text-muted-foreground text-xs leading-5">
                                    Best sellers and newest products. Turn off for carrier bags, charges and other small extras.
                                </p>
                            </div>
                            <ToggleSwitch
                                id="product-featured"
                                checked={!form.data.not_featured}
                                disabled={form.processing || form.data.hidden}
                                onCheckedChange={(on) => form.setData('not_featured', !on)}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label>Photo</Label>
                            <ImagePicker
                                label="Product photo"
                                current={product.photoUrl}
                                file={form.data.photo}
                                removed={form.data.remove_photo}
                                onPick={(f) => form.setData('photo', f)}
                                onRemove={(r) => form.setData('remove_photo', r)}
                                error={errors.photo}
                                disabled={form.processing}
                                help={
                                    product.tillPhotoUrl
                                        ? 'Your till’s picture shows in the store until you add one here. JPG, PNG or WebP, up to 4 MB.'
                                        : 'A clear photo on a plain background. JPG, PNG or WebP, up to 4 MB.'
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="product-description">Short description</Label>
                            <Textarea
                                id="product-description"
                                rows={4}
                                maxLength={descriptionMax}
                                value={form.data.description}
                                disabled={form.processing}
                                placeholder={product.tillDescription ?? 'What it is, size, origin…'}
                                aria-invalid={!!errors.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
                            {errors.description ? (
                                <p className="text-danger-foreground text-xs">{errors.description}</p>
                            ) : (
                                <p className="text-muted-foreground text-xs">
                                    {form.data.description.length} of {descriptionMax} characters.
                                    {product.tillDescription ? ' Empty uses the description from your tills.' : ''}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="product-allergens">Ingredients &amp; allergens</Label>
                            <Textarea
                                id="product-allergens"
                                rows={4}
                                maxLength={descriptionMax}
                                value={form.data.allergen_info}
                                disabled={form.processing}
                                placeholder={
                                    product.tillAllergens ?? 'Ingredients: … Allergens (in bold on the label): milk, wheat … May contain: nuts.'
                                }
                                aria-invalid={!!errors.allergen_info}
                                aria-describedby="product-allergens-help"
                                onChange={(e) => form.setData('allergen_info', e.target.value)}
                            />
                            {errors.allergen_info ? (
                                <p className="text-danger-foreground text-xs">{errors.allergen_info}</p>
                            ) : (
                                <p id="product-allergens-help" className="text-muted-foreground text-xs leading-5">
                                    Shown on the product page before customers order, with &ldquo;Always check the label. If you have an allergy, ask
                                    the shop.&rdquo;
                                    {product.tillAllergens
                                        ? ' Empty uses the allergens from your tills.'
                                        : ' Food law needs this for food sold online: copy it from the label.'}
                                </p>
                            )}
                        </div>

                        {(errors.product_id || errors.hidden) && (
                            <p className="text-danger-foreground text-sm">{errors.product_id ?? errors.hidden}</p>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" disabled={form.processing} onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing || !form.isDirty}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                Save details
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
