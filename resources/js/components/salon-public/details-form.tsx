import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { MarketingTicks, SalonField, SalonTick, type MarketingChoice } from './fields';
import { type BookingRules } from './types';

export interface ClientDetails {
    firstName: string;
    lastName: string;
    phone: string;
    email: string;
    notes: string;
    answers: string[];
    patchTest: 'yes' | 'no' | null;
    terms: boolean;
    marketing: MarketingChoice;
}

export const emptyDetails = (questions: number): ClientDetails => ({
    firstName: '',
    lastName: '',
    phone: '',
    email: '',
    notes: '',
    answers: Array.from({ length: questions }, () => ''),
    patchTest: null,
    terms: false,
    marketing: { sms: false, email: false, whatsApp: false },
});

/**
 * The client's part of a booking request (W3a, README §5, §11): name, phone and e-mail; the shop's questions; the
 * patch-test question when a chosen service needs one; the shop's terms (a separate tick); and the three marketing
 * boxes, never ticked for them. No health details are asked for.
 */
export function DetailsForm({
    rules,
    shopName,
    needsPatchTest,
    value,
    errors,
    onChange,
}: {
    rules: BookingRules;
    shopName: string;
    needsPatchTest: boolean;
    value: ClientDetails;
    errors: Record<string, string>;
    onChange: (value: ClientDetails) => void;
}) {
    const set = <K extends keyof ClientDetails>(key: K, v: ClientDetails[K]) => onChange({ ...value, [key]: v });

    return (
        <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <SalonField
                    id="firstName"
                    label="First name"
                    autoComplete="given-name"
                    maxLength={60}
                    value={value.firstName}
                    error={errors.firstName}
                    onChange={(e) => set('firstName', e.target.value)}
                />
                <SalonField
                    id="lastName"
                    label="Last name"
                    autoComplete="family-name"
                    maxLength={60}
                    value={value.lastName}
                    error={errors.lastName}
                    onChange={(e) => set('lastName', e.target.value)}
                />
                <SalonField
                    id="phone"
                    label="Mobile number"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    maxLength={30}
                    value={value.phone}
                    error={errors.phone}
                    onChange={(e) => set('phone', e.target.value)}
                />
                <SalonField
                    id="email"
                    label="E-mail"
                    type="email"
                    autoComplete="email"
                    maxLength={120}
                    value={value.email}
                    error={errors.email}
                    onChange={(e) => set('email', e.target.value)}
                />
            </div>

            {rules.questions.map((question, i) => (
                <SalonField
                    key={question}
                    id={`answer-${i}`}
                    label={question}
                    hint="Keep medical details for when you are in the shop."
                    error={errors.answers}
                >
                    <Textarea
                        id={`answer-${i}`}
                        rows={2}
                        maxLength={500}
                        value={value.answers[i] ?? ''}
                        onChange={(e) =>
                            set(
                                'answers',
                                value.answers.map((a, j) => (j === i ? e.target.value : a)),
                            )
                        }
                    />
                </SalonField>
            ))}

            {needsPatchTest && rules.askPatchTest && (
                <fieldset className="space-y-2">
                    <legend className="font-medium">Have you had a patch test with us for this service?</legend>
                    <div className="flex gap-2">
                        {(['yes', 'no'] as const).map((answer) => (
                            <button
                                key={answer}
                                type="button"
                                aria-pressed={value.patchTest === answer}
                                onClick={() => set('patchTest', answer)}
                                className={cn(
                                    'focus-visible:ring-ring/30 rounded-lg border px-4 py-1.5 font-medium outline-none focus-visible:ring-[3px]',
                                    value.patchTest === answer
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-card hover:border-primary/60',
                                )}
                            >
                                {answer === 'yes' ? 'Yes' : 'No'}
                            </button>
                        ))}
                    </div>
                    {errors.patchTest && <p className="text-destructive text-xs">{errors.patchTest}</p>}
                </fieldset>
            )}

            <div className="space-y-1.5">
                <Label htmlFor="notes">Anything the shop should know (optional)</Label>
                <Textarea id="notes" rows={2} maxLength={450} value={value.notes} onChange={(e) => set('notes', e.target.value)} />
                {errors.notes && <p className="text-destructive text-xs">{errors.notes}</p>}
            </div>

            {rules.depositNote !== '' && <p className="text-muted-foreground bg-muted rounded-lg p-3 text-xs">{rules.depositNote}</p>}

            {rules.terms !== '' && (
                <div className="space-y-2">
                    <p className="bg-muted rounded-lg p-3 text-xs whitespace-pre-line">{rules.terms}</p>
                    <SalonTick id="terms" checked={value.terms} onChange={(terms) => set('terms', terms)}>
                        I accept the shop's terms
                    </SalonTick>
                    {errors.terms && <p className="text-destructive text-xs">{errors.terms}</p>}
                </div>
            )}

            <MarketingTicks value={value.marketing} onChange={(marketing) => set('marketing', marketing)} shopName={shopName} />
        </div>
    );
}
