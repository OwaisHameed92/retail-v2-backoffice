import { cn } from '@/lib/utils';
import { Check, CreditCard, HandCoins, Store } from 'lucide-react';
import { useId } from 'react';

/** OS-9: a way to pay the store offers for the chosen method (App\Http\Support\Store\CheckoutPage::payments). */
export interface PaymentOption {
    value: 'payAtShop' | 'cardOnline' | 'payOnDelivery';
    label: string;
    hint: string;
    available: boolean;
}

const ICONS = { payAtShop: Store, cardOnline: CreditCard, payOnDelivery: HandCoins };

/** The ways to pay: one card when there is only one, else a choice. Card online sends the customer to DNA's page. */
export function PaymentChoice({
    options,
    value,
    onChange,
    error,
}: {
    options: PaymentOption[];
    value: string;
    onChange: (value: PaymentOption['value']) => void;
    error?: string;
}) {
    const name = useId();

    return (
        <div className="flex flex-col gap-3">
            <fieldset className="grid gap-3">
                <legend className="sr-only">How you pay</legend>
                {options.map((option) => {
                    const Icon = ICONS[option.value];
                    const selected = option.value === value || options.length === 1;

                    return (
                        <label
                            key={option.value}
                            className={cn(
                                'has-[:focus-visible]:outline-ink flex items-center gap-4 rounded-[22px] p-5 transition has-[:focus-visible]:outline-3',
                                selected ? 'bg-ink text-white' : 'bg-paper text-ink hover:bg-line',
                                option.available ? 'cursor-pointer' : 'cursor-not-allowed opacity-60',
                            )}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={option.value}
                                checked={selected}
                                disabled={!option.available}
                                onChange={() => onChange(option.value)}
                                className="sr-only"
                            />
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex size-11 shrink-0 items-center justify-center rounded-full',
                                    selected ? 'bg-lime text-ink' : 'bg-white',
                                )}
                            >
                                <Icon className="size-5" />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="font-display text-xl font-extrabold">{option.label}</span>
                                <span className={cn('text-sm', selected ? 'text-white/85' : 'text-body')}>{option.hint}</span>
                            </span>
                            {selected && <Check aria-hidden="true" className="text-lime size-5 shrink-0" />}
                        </label>
                    );
                })}
            </fieldset>
            {error && (
                <p role="alert" className="text-sale text-sm font-semibold">
                    {error}
                </p>
            )}
        </div>
    );
}
