import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/** Placeholder while the figures load (the deferred `trading` prop). */
export function TradingSkeleton() {
    return (
        <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading trading figures">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }, (_, i) => (
                    <Card key={i} className="flex flex-col gap-3 p-5">
                        <div className="flex items-center gap-3">
                            <Skeleton className="size-10 rounded-full" />
                            <Skeleton className="h-4 w-28" />
                        </div>
                        <Skeleton className="h-9 w-32" />
                        <Skeleton className="h-4 w-36" />
                        <Skeleton className="h-14 w-full" />
                    </Card>
                ))}
            </div>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <Card className="p-5 xl:col-span-8">
                    <Skeleton className="mb-4 h-5 w-40" />
                    <Skeleton className="h-64 w-full" />
                </Card>
                <Card className="p-5 xl:col-span-4">
                    <Skeleton className="mb-4 h-5 w-32" />
                    <Skeleton className="mx-auto size-40 rounded-full" />
                </Card>
            </div>
        </div>
    );
}
