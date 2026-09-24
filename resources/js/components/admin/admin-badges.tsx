import { Badge } from '@/components/ui/badge';

export function AdminRoleBadge({ label }: { label: string }) {
    return (
        <Badge variant="outline" className="font-normal">
            {label}
        </Badge>
    );
}

export function AdminStatusBadge({ isActive }: { isActive: boolean }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span className={isActive ? 'size-2 rounded-full bg-success' : 'bg-muted-foreground/50 size-2 rounded-full'} />
            <span className={isActive ? '' : 'text-muted-foreground'}>{isActive ? 'Active' : 'Inactive'}</span>
        </span>
    );
}
