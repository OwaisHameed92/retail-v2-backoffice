import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, router } from '@inertiajs/react';
import { LogOut, PauseCircle } from 'lucide-react';

interface AccountOnHoldProps {
    companyName: string;
    otherCompanies: { id: string; name: string }[];
}

/** Shown instead of the portal while the user's business is suspended. No business data on this page. */
export default function AccountOnHold({ companyName, otherCompanies }: AccountOnHoldProps) {
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
