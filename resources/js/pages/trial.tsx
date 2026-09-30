import { FormField } from '@/components/shared/form-section';
import { TrialSuccess } from '@/components/trial/trial-success';
import { TurnstileWidget } from '@/components/trial/turnstile-widget';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AuthLayout from '@/layouts/auth-layout';
import { Head } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { useCallback, useState, type FormEvent, type InputHTMLAttributes } from 'react';

interface TrialPageProps {
    endpoint: string;
    turnstileSiteKey: string | null;
    businessTypes: { value: string; label: string }[];
    maxShops: number;
    maxTills: number;
    supportEmail: string;
    supportPhone: string | null;
}

type Field = 'businessName' | 'contactName' | 'email' | 'phone' | 'town' | 'postcode' | 'shopsCount' | 'tillsCount' | 'businessType' | 'currentSystem';

const EMPTY: Record<Field, string> = {
    businessName: '',
    contactName: '',
    email: '',
    phone: '',
    town: '',
    postcode: '',
    shopsCount: '1',
    tillsCount: '1',
    businessType: '',
    currentSystem: '',
};

/** utm_source, utm_medium and utm_campaign from the page address, passed on as the API's `utm` object. */
function utmFromUrl(): Record<string, string> | null {
    const params = new URLSearchParams(window.location.search);
    const utm = Object.fromEntries(
        (['source', 'medium', 'campaign'] as const).flatMap((key) => {
            const value = params.get(`utm_${key}`);
            return value ? [[key, value]] : [];
        }),
    );

    return Object.keys(utm).length > 0 ? utm : null;
}

/**
 * Hosted trial request form (module 1.10). Posts JSON to the public API with fetch, exactly as the marketing website
 * will (docs/specs/public-trial-api.md), and shows field errors from `details.fields`.
 */
export default function Trial({ endpoint, turnstileSiteKey, businessTypes, maxShops, maxTills, supportEmail, supportPhone }: TrialPageProps) {
    const [data, setData] = useState(EMPTY);
    const [consent, setConsent] = useState(false);
    const [website, setWebsite] = useState('');
    const [token, setToken] = useState<string | null>(null);
    const [resetKey, setResetKey] = useState(0);
    const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
    const [problem, setProblem] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [done, setDone] = useState<{ reference: string; message: string } | null>(null);
    const onToken = useCallback((next: string | null) => setToken(next), []);

    const set = (field: Field, value: string) => setData((current) => ({ ...current, [field]: value }));

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setProblem(null);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    ...data,
                    shopsCount: Number(data.shopsCount),
                    tillsCount: Number(data.tillsCount),
                    currentSystem: data.currentSystem || null,
                    marketingConsent: consent,
                    utm: utmFromUrl(),
                    captchaToken: token,
                    website,
                }),
            });
            const body = await response.json().catch(() => ({}));

            if (response.status === 201) {
                setDone({ reference: body.reference, message: body.message });
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const fields = (body.details?.fields ?? {}) as Record<string, string[]>;
            setErrors(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) as Partial<Record<Field, string>>);
            setProblem(response.status === 400 ? 'Please check the highlighted fields.' : (body.message ?? 'Something went wrong. Please try again.'));
            if (turnstileSiteKey && response.status !== 400) {
                setResetKey((key) => key + 1);
            }
        } catch {
            setProblem('We could not send your request. Check your internet connection and try again.');
        } finally {
            setProcessing(false);
        }
    };

    const input = (field: Field, props: InputHTMLAttributes<HTMLInputElement> = {}) => (
        <Input
            id={field}
            name={field}
            value={data[field]}
            onChange={(e) => set(field, e.target.value)}
            aria-invalid={!!errors[field] || undefined}
            aria-describedby={errors[field] ? `${field}-error` : undefined}
            {...props}
        />
    );

    return (
        <AuthLayout
            variant="trial"
            title={done ? 'Request received' : 'Start your free trial'}
            description={done ? undefined : 'Tell us a little about your shop. We will call you to set everything up — no card needed.'}
        >
            <Head title="Free trial" />

            {done ? (
                <TrialSuccess firstName={data.contactName.trim().split(/\s+/)[0] ?? ''} reference={done.reference} message={done.message} supportEmail={supportEmail} />
            ) : (
                <form className="grid gap-5" onSubmit={submit} noValidate>
                    {problem && (
                        <Alert variant="destructive">
                            <CircleAlert className="size-4" aria-hidden />
                            <AlertDescription>{problem}</AlertDescription>
                        </Alert>
                    )}

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField id="businessName" label="Business name" error={errors.businessName} className="sm:col-span-2">
                            {input('businessName', { autoComplete: 'organization', required: true, autoFocus: true, placeholder: 'Khan Mini Mart' })}
                        </FormField>
                        <FormField id="contactName" label="Your name" error={errors.contactName}>
                            {input('contactName', { autoComplete: 'name', required: true })}
                        </FormField>
                        <FormField id="phone" label="Phone" error={errors.phone}>
                            {input('phone', { type: 'tel', autoComplete: 'tel', required: true, placeholder: '07700 900123' })}
                        </FormField>
                        <FormField id="email" label="Email address" error={errors.email} className="sm:col-span-2">
                            {input('email', { type: 'email', autoComplete: 'email', required: true, placeholder: 'you@yourshop.co.uk' })}
                        </FormField>
                        <FormField id="town" label="Town" error={errors.town}>
                            {input('town', { autoComplete: 'address-level2', required: true })}
                        </FormField>
                        <FormField id="postcode" label="Postcode" error={errors.postcode}>
                            {input('postcode', { autoComplete: 'postal-code', required: true, className: 'uppercase' })}
                        </FormField>
                        <FormField id="businessType" label="Type of business" error={errors.businessType} className="sm:col-span-2">
                            <Select value={data.businessType} onValueChange={(value) => set('businessType', value)}>
                                <SelectTrigger id="businessType" aria-invalid={!!errors.businessType || undefined}>
                                    <SelectValue placeholder="Choose one" />
                                </SelectTrigger>
                                <SelectContent>
                                    {businessTypes.map((type) => (
                                        <SelectItem key={type.value} value={type.value}>
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField id="shopsCount" label="Shops" error={errors.shopsCount}>
                            {input('shopsCount', { type: 'number', min: 1, max: maxShops, inputMode: 'numeric', required: true, className: 'tabular-nums' })}
                        </FormField>
                        <FormField id="tillsCount" label="Tills in total" error={errors.tillsCount}>
                            {input('tillsCount', { type: 'number', min: 1, max: maxTills, inputMode: 'numeric', required: true, className: 'tabular-nums' })}
                        </FormField>
                        <FormField id="currentSystem" label="What do you use now?" optional error={errors.currentSystem} className="sm:col-span-2">
                            {input('currentSystem', { placeholder: 'Another EPOS, a cash register, pen and paper…' })}
                        </FormField>
                    </div>

                    {/* Honeypot: hidden from people; bots that fill it get a normal reply and nothing is saved. */}
                    <div className="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden>
                        <label htmlFor="website">Website</label>
                        <input id="website" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox id="marketingConsent" checked={consent} onCheckedChange={(checked) => setConsent(checked === true)} className="mt-0.5" />
                        <Label htmlFor="marketingConsent" className="text-muted-foreground text-[13px] leading-5 font-normal">
                            Send me occasional news and offers from Switch &amp; Save. You can unsubscribe at any time.
                        </Label>
                    </div>

                    {turnstileSiteKey && <TurnstileWidget siteKey={turnstileSiteKey} onToken={onToken} resetKey={resetKey} />}

                    <Button type="submit" size="lg" className="w-full" disabled={processing || (!!turnstileSiteKey && !token)}>
                        {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                        Request my free trial
                    </Button>

                    <p className="text-muted-foreground text-center text-[13px] leading-5">
                        Prefer to talk? {supportPhone ? `Call ${supportPhone} or email ` : 'Email '}
                        <a className="text-primary font-medium hover:underline" href={`mailto:${supportEmail}`}>
                            {supportEmail}
                        </a>
                        .
                    </p>
                </form>
            )}
        </AuthLayout>
    );
}
