import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck, Wrench } from 'lucide-react';

interface Shop {
    id: string;
    name: string;
    town: string | null;
    included: boolean;
    status: 'notConnected' | 'pending' | 'connected' | 'failed' | 'disconnected';
}

interface Props {
    error: string | null;
    request: { clientId: string; redirectUri: string; scope: string; state: string; host: string } | null;
    scopes: string[];
    business: string;
    shops: Shop[];
}

const SCOPE_WORDS: Record<string, string> = {
    'products:read': 'See the shop’s products',
    'inventory:read': 'See stock and shelf prices',
    'customers:read': 'See customers’ names, phone numbers, e-mails and addresses',
    'customers:write': 'Add customers and change their contact details',
    'stores:read': 'See the shop’s name and address',
    'taxrates:read': 'See VAT rates',
    'salesorders:write': 'Send repair jobs to the shop’s till as workshop orders',
    'webhooks:write': 'Ask to be told when orders are paid and stock changes',
    offline_access: 'Keep working when you are signed out',
};

/** W5: the shop owner lets BikeBook use one shop (OAuth consent). */
export default function WorkshopAuthorize({ error, request, scopes, business, shops }: Props) {
    const first = shops.find((shop) => shop.included);
    const form = useForm({
        client_id: request?.clientId ?? '',
        redirect_uri: request?.redirectUri ?? '',
        scope: request?.scope ?? '',
        state: request?.state ?? '',
        branch_id: shops.length === 1 && first ? first.id : '',
        decision: 'approve',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const answer = (decision: 'approve' | 'deny') => {
        form.transform((data) => ({ ...data, decision }));
        form.post(route('workshop.oauth.authorize.store'));
    };

    return (
        <AppLayout>
            <Head title="Connect BikeBook" />
            <PageHeader title="Connect BikeBook" description={`BikeBook asks to work with ${business}.`} />

            <div className="mx-auto grid w-full max-w-2xl gap-6">
                {error || !request ? (
                    <Alert variant="destructive">
                        <AlertDescription>{error ?? 'This link is not valid.'}</AlertDescription>
                    </Alert>
                ) : (
                    <>
                        <SectionCard title="Choose the shop" description="BikeBook connects to one shop. Its repair jobs go to that shop’s till.">
                            <fieldset className="grid gap-2" aria-describedby="shop-error">
                                <legend className="sr-only">Shop</legend>
                                {shops.length === 0 && <p className="text-muted-foreground text-sm">You have no open shop to connect.</p>}
                                {shops.map((shop) => (
                                    <label
                                        key={shop.id}
                                        className={cn(
                                            'flex items-center justify-between gap-3 rounded-lg border p-3 text-sm',
                                            shop.included ? 'hover:bg-muted/50 cursor-pointer' : 'cursor-not-allowed opacity-60',
                                            form.data.branch_id === shop.id && 'border-primary ring-primary/30 ring-2',
                                        )}
                                    >
                                        <span className="flex items-center gap-3">
                                            <input
                                                type="radio"
                                                name="branch_id"
                                                value={shop.id}
                                                checked={form.data.branch_id === shop.id}
                                                disabled={!shop.included}
                                                onChange={() => form.setData('branch_id', shop.id)}
                                                className="accent-primary size-4"
                                            />
                                            <span className="grid">
                                                <span className="font-medium">{shop.name}</span>
                                                <span className="text-muted-foreground">
                                                    {shop.included
                                                        ? (shop.town ?? 'No town set')
                                                        : 'Not in this shop’s licence. Contact Switch & Save.'}
                                                </span>
                                            </span>
                                        </span>
                                        {shop.status === 'connected' && <StatusBadge status="connected" label="Connected" tone="success" />}
                                    </label>
                                ))}
                            </fieldset>
                            <InputError id="shop-error" message={errors.branch_id ?? errors.request ?? errors.scope} className="mt-2" />
                        </SectionCard>

                        <SectionCard title="BikeBook will be able to">
                            <ul className="grid gap-2 text-sm">
                                {scopes.map((scope) => (
                                    <li key={scope} className="flex items-start gap-2">
                                        <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" aria-hidden />
                                        {SCOPE_WORDS[scope] ?? scope}
                                    </li>
                                ))}
                            </ul>
                            <p className="text-muted-foreground mt-4 text-sm">
                                BikeBook never sees customers’ balances, points, credit limits, cards, dates of birth or notes. You can ask Switch &
                                Save to disconnect it at any time. You will go back to {request.host}.
                            </p>
                        </SectionCard>

                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => answer('deny')}>
                                Decline
                            </Button>
                            <Button type="button" disabled={form.processing || form.data.branch_id === ''} onClick={() => answer('approve')}>
                                {form.processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Wrench aria-hidden />}
                                Allow BikeBook
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
