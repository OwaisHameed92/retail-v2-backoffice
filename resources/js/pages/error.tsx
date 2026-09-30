import BrandLogo from '@/components/brand-logo';
import { EmptyState } from '@/components/shared/empty-state';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban, CloudOff, FileQuestion, TriangleAlert, type LucideIcon } from 'lucide-react';

const COPY: Record<number, { title: string; body: string; icon: LucideIcon }> = {
    403: { title: 'You do not have access to this page', body: 'Ask an owner of your business to change your role if you need it.', icon: Ban },
    404: {
        title: 'We could not find this page',
        body: 'The link may be old, or the item was removed. Check the address or go back.',
        icon: FileQuestion,
    },
    500: {
        title: 'Something went wrong on our side',
        body: 'Try again in a minute. Your tills keep trading and nothing they sent is lost.',
        icon: TriangleAlert,
    },
    503: {
        title: 'The backoffice is being updated',
        body: 'We will be back in a few minutes. Your tills keep trading and sync when we return.',
        icon: CloudOff,
    },
};

/** Branded 403 / 404 / 500 / 503 page (rendered by `App\Http\Support\InertiaErrorPages` when debug is off). */
export default function ErrorPage({ status, home }: { status: number; home: string }) {
    const base = COPY[status] ?? COPY[500];
    const copy = status === 403 && home === '/admin' ? { ...base, body: 'Ask an owner of the admin team to change your role if you need it.' } : base;

    return (
        <main className="bg-background flex min-h-svh flex-col items-center justify-center gap-8 px-4 py-10">
            <Head title={copy.title} />
            <BrandLogo className="h-9" />
            <div className="bg-card shadow-card w-full max-w-lg rounded-xl border">
                <EmptyState
                    icon={copy.icon}
                    tone={status >= 500 ? 'warning' : 'neutral'}
                    title={copy.title}
                    body={
                        <>
                            {copy.body}
                            <span className="text-muted-foreground/80 mt-2 block text-xs tabular-nums">Error {status}</span>
                        </>
                    }
                    action={
                        <>
                            <Button variant="outline" onClick={() => window.history.back()}>
                                <ArrowLeft className="size-4" aria-hidden />
                                Go back
                            </Button>
                            <Button asChild>
                                <Link href={home}>Go to the dashboard</Link>
                            </Button>
                        </>
                    }
                />
            </div>
        </main>
    );
}
