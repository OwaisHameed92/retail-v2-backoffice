import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Landmark, LogOut, PauseCircle } from 'lucide-react';

interface AccountOnHoldProps {
    companyName: string;
    otherCompanies: { id: string; name: string }[];
    /** Module 1.13: set when the hold is lifted by setting up Direct Debit (users with billing.view). */
    directDebitUrl?: string | null;
    billingUrl?: string | null;
}

/** Shown instead of the portal while the user's business is suspended. No business data on this page. */
export default function AccountOnHold({ companyName, otherCompanies, directDebitUrl = null, billingUrl = null }: AccountOnHoldProps) {
    return (
        <AuthLayout
            title="Your account is on hold"
            description={`${companyName}’s Switch & Save account is on hold. Please contact Switch & Save support.`}
        >
            <Head title="Account on hold" />

            <div className="flex flex-col items-center gap-6">
                <div className="bg-warning-soft text-warning-foreground flex size-12 items-center justify-center rounded-full">
                    <PauseCircle className="size-6" aria-hidden />
                </div>

                <p className="text-muted-foreground text-center text-sm">
                    Support can help with billing or anything else. Your data is safe and your access returns as soon as the hold is lifted.
                </p>

                {directDebitUrl ? (
                    <div className="grid w-full gap-2 text-center">
                        <p className="text-sm">Set up your Direct Debit and your tills unlock straight away.</p>
                        <Button asChild>
                            <Link href={directDebitUrl}>
                                <Landmark />
                                Set up Direct Debit
                            </Link>
                        </Button>
                    </div>
                ) : (
                    billingUrl && (
                        <Button variant="outline" asChild className="w-full">
                            <Link href={billingUrl}>View billing</Link>
                        </Button>
                    )
                )}

                {otherCompanies.length > 0 && (
                    <div className="grid w-full gap-2">
                        <p className="text-center text-sm font-medium">Open another business</p>
                        {otherCompanies.map((company) => (
                            <Button
                                key={company.id}
                                variant="outline"
                                onClick={() => router.post(route('app.company.switch'), { company_id: company.id })}
                            >
                                {company.name}
                            </Button>
                        ))}
                    </div>
                )}

                <Button variant="ghost" asChild>
                    <Link href={route('logout')} method="post" as="button">
                        <LogOut />
                        Log out
                    </Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
