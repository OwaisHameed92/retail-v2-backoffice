import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { MasterFields } from './master-fields';
import { type ContributionRow, type MasterOptions, type MasterValues } from './types';

/** Approve a till's barcode: check its details, then it joins the master catalogue as "From tills". */
export function ApproveDialog({ row, options, onClose }: { row: ContributionRow; options: MasterOptions; onClose: () => void }) {
    const { data, setData, post, processing, errors, transform } = useForm<MasterValues>({
        barcode: row.barcode,
        name: row.name,
        brand: '',
        size_value: row.sizeValue,
        size_unit: row.sizeUnit,
        pack_qty: row.packQty,
        department: '',
        category: '',
        vat_rate: '',
        rrp: '',
        age_rule: 'none',
        image_url: '',
        in_starter_packs: false,
    });
    const set = <K extends keyof MasterValues>(key: K, value: MasterValues[K]) => setData(key, value as never);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((values) => ({ ...values, barcode: '' }));
        post(route('admin.catalogue.contributions.approve', row.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Add {row.barcode} to the catalogue</DialogTitle>
                        <DialogDescription>
                            Seen {row.seen === 1 ? 'once' : `${row.seen} times`} on tills. Check the name and fill in what you know: shops will see
                            these details when they scan it.
                        </DialogDescription>
                    </DialogHeader>
                    <MasterFields data={data} setData={set} errors={errors} options={options} withBarcode={false} compact />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Approve and add
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
