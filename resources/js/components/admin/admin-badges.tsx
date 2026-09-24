import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';

export function AdminRoleBadge({ label }: { label: string }) {
    return <Badge variant={label === 'Owner' ? 'info' : 'neutral'}>{label}</Badge>;
}

export function AdminStatusBadge({ isActive }: { isActive: boolean }) {
    return <StatusBadge status={isActive ? 'active' : 'inactive'} />;
}
