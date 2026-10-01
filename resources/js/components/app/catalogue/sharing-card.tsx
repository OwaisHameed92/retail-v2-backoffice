import { CheckRow } from '@/components/app/products/fields';
import { SectionCard } from '@/components/shared/section-card';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/** The business's choice to share unknown barcodes with the SSPOS catalogue (anonymous, on by default). */
export function SharingCard({ sharing }: { sharing: boolean }) {
    const [saving, setSaving] = useState(false);

    return (
        <SectionCard title="Help grow the catalogue" description="Every business benefits when the catalogue knows more products.">
            <CheckRow
                id="share_unknown_barcodes"
                checked={sharing}
                disabled={saving}
                onChange={(share) =>
                    router.put(
                        route('app.products.catalogue.sharing'),
                        { share },
                        { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
                    )
                }
                label="Share new barcodes anonymously"
                help="When your tills sell a barcode the catalogue does not know, SSPOS receives the barcode, product name and size only: never your prices, sales, business or shop. Our team checks each one before it joins the catalogue."
            />
        </SectionCard>
    );
}
