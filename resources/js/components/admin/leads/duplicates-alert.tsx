import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Link } from '@inertiajs/react';
import { CopyCheck } from 'lucide-react';
import { leadStatusTones } from './format';
import { type DuplicateMatch } from './types';

/** Warns that another lead or a tenant shares this lead's email or phone, with links to check them. */
export function DuplicatesAlert({ matches, canViewTenants }: { matches: DuplicateMatch[]; canViewTenants: boolean }) {
    if (matches.length === 0) {
        return null;
    }

    return (
        <Alert variant="warning">
            <CopyCheck className="size-4" />
            <AlertTitle>Possible duplicate</AlertTitle>
            <AlertDescription>
                <p>The same email or phone number is used by:</p>
                <ul className="mt-2 grid gap-1.5">
                    {matches.map((match) => {
                        const href =
                            match.type === 'tenant'
                                ? canViewTenants
                                    ? route('admin.tenants.show', match.id)
                                    : null
                                : route('admin.leads.show', match.id);

                        return (
                            <li key={`${match.type}-${match.id}`} className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="text-foreground/70 text-xs font-medium tracking-wide uppercase">
                                    {match.type === 'tenant' ? 'Tenant' : 'Lead'}
                                </span>
                                {href ? (
                                    <Link href={href} className="font-medium underline underline-offset-2">
                                        {match.name}
                                    </Link>
                                ) : (
                                    <span className="font-medium">{match.name}</span>
                                )}
                                <StatusBadge status={match.status} tones={match.type === 'lead' ? leadStatusTones : undefined} />
                                <span className="text-sm">same {match.matchedOn.join(' and ')}</span>
                            </li>
                        );
                    })}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
