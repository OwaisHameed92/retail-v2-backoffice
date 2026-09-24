import { type AdminSession } from '@/components/admin/types';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';

export function AdminUserMenu({ admin }: { admin: AdminSession }) {
    const getInitials = useInitials();
    const cleanup = useMobileNavigation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="size-9 rounded-full p-1" aria-label="Account menu">
                    <Avatar className="size-8 overflow-hidden rounded-full">
                        <AvatarFallback className="rounded-full bg-neutral-200 text-sm text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(admin.name)}
                        </AvatarFallback>
                    </Avatar>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-60" align="end">
                <DropdownMenuLabel className="font-normal">
                    <div className="grid gap-1 text-sm">
                        <span className="truncate font-medium">{admin.name}</span>
                        <span className="text-muted-foreground truncate text-xs">{admin.email}</span>
                        <Badge variant="outline" className="mt-1 w-fit font-normal">
                            {admin.roleLabel}
                        </Badge>
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link className="block w-full" method="post" href={route('admin.logout')} as="button" onClick={cleanup}>
                        <LogOut className="mr-2" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
