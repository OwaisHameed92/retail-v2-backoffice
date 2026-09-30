import { type RequestOptions, type ShopRequestKind } from '@/components/app/shops/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, Monitor, Store } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RequestDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: RequestOptions;
    /** Pre-selects this shop for "More tills". */
    shopId?: string;
}

type RequestForm = { kind: ShopRequestKind; branch_id: string; tills: string; new_shop_name: string; phone: string; message: string };

/**
 * "Ask for more tills / another shop" (module 4.7). Sends a request to the Switch & Save team; nothing changes until
 * they add the tills or the shop. One-shop users can ask for their own shop's tills only.
 */
export function RequestDialog(props: RequestDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <RequestDialogBody {...props} />}
        </Dialog>
    );
}

function RequestDialogBody({ onOpenChange, options, shopId }: RequestDialogProps) {
    const { data, setData, post, processing, errors } = useForm<RequestForm>({
        kind: 'moreTills',
        branch_id: shopId ?? (options.shops.length === 1 ? options.shops[0].id : ''),
        tills: '1',
        new_shop_name: '',
        phone: '',
        message: '',
    });
    const newShop = data.kind === 'newShop';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('app.shops.requests.store'), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-lg">
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{options.canAskForShop ? 'Ask for more tills or another shop' : 'Ask for more tills'}</DialogTitle>
                    <DialogDescription>
                        We will get in touch to agree the price and set it up. Nothing changes on your tills or your bill until then.
                    </DialogDescription>
                </DialogHeader>

                {options.canAskForShop && (
                    <FormField id="kind" label="What do you need?" error={errors.kind}>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={data.kind}
                            onValueChange={(value) => value && setData('kind', value as ShopRequestKind)}
                            className="w-full"
                            aria-label="What do you need?"
                        >
                            <ToggleGroupItem value="moreTills" className="flex-1 gap-2">
                                <Monitor aria-hidden className="size-4" />
                                More tills
                            </ToggleGroupItem>
                            <ToggleGroupItem value="newShop" className="flex-1 gap-2">
                                <Store aria-hidden className="size-4" />
                                Another shop
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </FormField>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem]">
                    {newShop ? (
                        <FormField id="new_shop_name" label="Where is the new shop?" error={errors.new_shop_name} help="A name or the town is enough.">
                            <Input
                                id="new_shop_name"
                                value={data.new_shop_name}
                                maxLength={120}
                                onChange={(e) => setData('new_shop_name', e.target.value)}
                                aria-invalid={errors.new_shop_name ? true : undefined}
                                placeholder="e.g. Harrogate, Station Parade"
                            />
                        </FormField>
                    ) : (
                        <FormField id="branch_id" label="Shop" error={errors.branch_id}>
                            <Select value={data.branch_id} onValueChange={(value) => setData('branch_id', value)} disabled={options.shops.length <= 1}>
                                <SelectTrigger id="branch_id" aria-invalid={errors.branch_id ? true : undefined}>
                                    <SelectValue placeholder="Choose a shop" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.shops.map((shop) => (
                                        <SelectItem key={shop.id} value={shop.id}>
                                            {shop.name} ({shop.code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                    )}
                    <FormField id="tills" label={newShop ? 'Tills there' : 'Extra tills'} error={errors.tills}>
                        <Input
                            id="tills"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={options.maxTills}
                            value={data.tills}
                            onChange={(e) => setData('tills', e.target.value)}
                            aria-invalid={errors.tills ? true : undefined}
                        />
                    </FormField>
                </div>

                <FormField id="phone" label="Best number to call" optional error={errors.phone}>
                    <Input
                        id="phone"
                        type="tel"
                        autoComplete="tel"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        aria-invalid={errors.phone ? true : undefined}
                    />
                </FormField>

                <FormField id="message" label="Anything we should know?" optional error={errors.message}>
                    <Textarea
                        id="message"
                        rows={3}
                        maxLength={1000}
                        value={data.message}
                        onChange={(e) => setData('message', e.target.value)}
                        placeholder="e.g. When you need it by, or what the till is for."
                    />
                </FormField>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                        Send request
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
